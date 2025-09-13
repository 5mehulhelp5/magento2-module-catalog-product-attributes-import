<?php
/**
 * Aimane Couissi - https://aimanecouissi.com
 * Copyright © Aimane Couissi 2025–present. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */

declare(strict_types=1);

namespace AimaneCouissi\CatalogProductAttributesImport\Service;

use Exception;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\File\Csv;
use Symfony\Component\Console\Output\OutputInterface;

class CsvService
{
    /**
     * @param DirectoryList $directoryList
     * @param Csv $csv
     */
    public function __construct(
        private readonly DirectoryList $directoryList,
        private readonly Csv           $csv,
    )
    {
    }

    /**
     * Resolves a CSV path relative to var/.
     *
     * @param string $csvArg
     * @return string|null
     */
    public function resolveCsvPath(string $csvArg): ?string
    {
        try {
            $varDir = $this->getVarDirPath();
            if ($varDir === null) {
                return null;
            }
            $candidate = $this->joinVarPath($varDir, $csvArg);
            return realpath($candidate) ?: $candidate;
        } catch (FileSystemException) {
            return null;
        }
    }

    /**
     * Gets the absolute var directory path.
     *
     * @return string|null
     * @throws FileSystemException
     */
    private function getVarDirPath(): ?string
    {
        $path = $this->directoryList->getPath(DirectoryList::VAR_DIR);
        return rtrim($path, DIRECTORY_SEPARATOR);
    }

    /**
     * Joins 'var' path and provided argument into a candidate path.
     *
     * @param string $varDir
     * @param string $csvArg
     * @return string
     */
    private function joinVarPath(string $varDir, string $csvArg): string
    {
        return $varDir . DIRECTORY_SEPARATOR . ltrim($csvArg, DIRECTORY_SEPARATOR);
    }

    /**
     * Validates that the CSV path exists and is readable.
     *
     * @param string $csvPath
     * @param OutputInterface $output
     * @return bool
     */
    public function validateCsvPath(string $csvPath, OutputInterface $output): bool
    {
        if (!is_file($csvPath) || !is_readable($csvPath)) {
            $output->writeln("<error>The CSV file '$csvPath' does not exist or is not readable</error>");
            return false;
        }
        return true;
    }

    /**
     * Reads and parses the CSV into an array.
     *
     * @param string $csvPath
     * @param OutputInterface $output
     * @return array|null
     */
    public function readCsvData(string $csvPath, OutputInterface $output): ?array
    {
        try {
            return $this->configureCsvReader()->getData($csvPath);
        } catch (Exception $e) {
            $output->writeln("<error>An error occurred while reading the CSV file '$csvPath': {$e->getMessage()}</error>");
            return null;
        }
    }

    /**
     * Configures the CSV reader for delimiter and enclosure.
     *
     * @return Csv
     */
    private function configureCsvReader(): Csv
    {
        return $this->csv->setDelimiter(',')->setEnclosure('"');
    }

    /**
     * Validates high-level CSV shape (header and rows).
     *
     * @param array $csvData
     * @param OutputInterface $output
     * @return bool
     */
    public function validateCsvData(array $csvData, OutputInterface $output): bool
    {
        if (empty($csvData)) {
            $output->writeln("<comment>The CSV file is empty</comment>");
            return false;
        }
        $header = $csvData[0];
        if (!$this->isHeaderValid($header)) {
            $output->writeln("<comment>The CSV file header is empty or contains only whitespace</comment>");
            return false;
        }
        if (count($csvData) <= 1) {
            $output->writeln("<comment>The CSV file contains only the header row</comment>");
            return false;
        }
        $headerCount = count($header);
        foreach (array_slice($csvData, 1, null, true) as $i => $row) {
            if ($this->isRowEmpty($row)) {
                continue;
            }
            if (!$this->validateRowColumnCount($row, $headerCount, $i + 1, $output)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Validates that the header row is non-empty and well-formed.
     *
     * @param array $header
     * @return bool
     */
    private function isHeaderValid(array $header): bool
    {
        return !empty(array_filter($header));
    }

    /**
     * Returns true when a row is considered empty after trimming.
     *
     * @param array $row
     * @return bool
     */
    private function isRowEmpty(array $row): bool
    {
        return empty(array_filter($row, fn($v) => $this->toTrimmedString($v) !== '' && !is_null($v)));
    }

    /**
     * Casts a mixed value to string and trims it.
     *
     * @param mixed $value
     * @return string
     */
    private function toTrimmedString(mixed $value): string
    {
        return trim((string)$value);
    }

    /**
     * Validates that a row has the same column count as the header.
     *
     * @param array $row
     * @param int $headerCount
     * @param int $line
     * @param OutputInterface $output
     * @return bool
     */
    private function validateRowColumnCount(array $row, int $headerCount, int $line, OutputInterface $output): bool
    {
        $rowColumnCount = count($row);
        if ($rowColumnCount !== $headerCount) {
            $output->writeln("<comment>The CSV file has a row on line $line with $rowColumnCount columns, but the header has $headerCount columns</comment>");
            return false;
        }
        return true;
    }

    /**
     * Builds a case-insensitive header map.
     *
     * @param string[] $header
     * @return array
     */
    public function buildHeaderMap(array $header): array
    {
        $map = [];
        foreach ($header as $index => $name) {
            $key = $this->normalizeHeaderKey($name);
            if ($key !== '' && !isset($map[$key])) {
                $map[$key] = $index;
            }
        }
        return $map;
    }

    /**
     * Normalizes a header cell into a case-insensitive key.
     *
     * @param mixed $name
     * @return string
     */
    private function normalizeHeaderKey(mixed $name): string
    {
        return strtolower(trim((string)$name));
    }

    /**
     * Iterates non-empty data rows in the CSV.
     *
     * @param array $csvData
     * @param callable $callback
     * @return void
     */
    public function iterateDataRows(array $csvData, callable $callback): void
    {
        foreach (array_slice($csvData, 1) as $row) {
            if ($this->isRowEmpty($row)) {
                continue;
            }
            $callback($row);
        }
    }

    /**
     * Reads a cell by the header key with trimming.
     *
     * @param array $row
     * @param array $headerMap
     * @param string $key
     * @return string
     */
    public function readCell(array $row, array $headerMap, string $key): string
    {
        if (!array_key_exists($key, $headerMap)) {
            return '';
        }
        $index = $headerMap[$key];
        return $this->toTrimmedString($row[$index] ?? '');
    }

    /**
     * Parses a semicolon-separated list.
     *
     * @param string $cell
     * @return string[]
     */
    public function parseList(string $cell): array
    {
        return array_filter(
            array_map('trim', explode(';', $cell)),
            static fn($v) => $v !== ''
        );
    }
}
