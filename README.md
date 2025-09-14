# AimaneCouissi_CatalogProductAttributesImport

[![Latest Stable Version](http://poser.pugx.org/aimanecouissi/module-catalog-product-attributes-import/v)](https://packagist.org/packages/aimanecouissi/module-catalog-product-attributes-import) [![Total Downloads](http://poser.pugx.org/aimanecouissi/module-catalog-product-attributes-import/downloads)](https://packagist.org/packages/aimanecouissi/module-catalog-product-attributes-import) [![Latest Unstable Version](http://poser.pugx.org/aimanecouissi/module-catalog-product-attributes-import/v/unstable)](https://packagist.org/packages/aimanecouissi/module-catalog-product-attributes-import) [![License](http://poser.pugx.org/aimanecouissi/module-catalog-product-attributes-import/license)](https://packagist.org/packages/aimanecouissi/module-catalog-product-attributes-import) [![PHP Version Require](http://poser.pugx.org/aimanecouissi/module-catalog-product-attributes-import/require/php)](https://packagist.org/packages/aimanecouissi/module-catalog-product-attributes-import)

Import product **attributes** from a CSV file via Magento CLI, with support for **add / update / delete**, option labels per store, default values, and assignment to **attribute sets** and **groups**. Also supports bulk **deletion of attribute sets** from CSV.

## Installation
```bash
composer require aimanecouissi/module-catalog-product-attributes-import
bin/magento module:enable AimaneCouissi_CatalogProductAttributesImport
bin/magento setup:upgrade
bin/magento cache:flush
```

## Usage
Run the importer against a CSV located **relative to `var/`**:
```bash
bin/magento catalog:product:attributes:import <csv> [-t|--type <attribute|attribute-set>] [-b|--behavior <add|update|delete>]
```
Options:
- `--type` (default `attribute`): `attribute` or `attribute-set`.
- `--behavior` (default `add`): for attributes use `add|update|delete`; for `attribute-set`, only `delete`.
- Verbose output: add `-v`, `-vv`, or `-vvv` for detailed logs (warnings, merges, store mapping, option de-dup, etc.).

## CSV Quick Reference

**General**
- Header row required. Values are comma-separated; lists inside a cell use **semicolon `;`**.
- Store-scoped columns use suffixes, e.g., `label_fr`, `option_en`, `option_de`.

**When `--type=attribute`**
- **Required:** `attribute_code`
- **Common (optional):** `label`, `input`, `default`, `apply_to`, `attribute_set`, `group`, `sort_order`, `group_order`, `attribute_set_order`, `label_{storeCode}`
- **Options (for `select` / `multiselect` without a `source` model):** `option`, `option_{storeCode}`, `option_order`, `option_strategy` (`replace` or merge by default)

**When `--type=attribute-set`**
- **Required:** `attribute_set` — names or IDs to delete (default set is never deleted).
- Only with `--behavior=delete`.

## Sample CSV
A starter file is included at [`Files/catalog_product_attributes_import_sample.csv`](Files/catalog_product_attributes_import_sample.csv). Copy it, keep the header, and fill rows with your data.

## Examples

**1) Add a new `color` select with options and translations**
```csv
attribute_code,label,input,option,option_fr,default,attribute_set,group,sort_order,option_order
color,Color,select,Red;Green;Blue,Rouge;Vert;Bleu,Green,Default,General,10,10;20;30
```
```bash
bin/magento catalog:product:attributes:import imports/attributes.csv
```

**2) Update existing `size` (merge new options)**
```csv
attribute_code,label,input,option,default,attribute_set,group
size,Size,select,XS;S;M;L;XL,M,Default,General
```
```bash
bin/magento catalog:product:attributes:import imports/size.csv --behavior update
```

**3) Replace existing options for `material`**
```csv
attribute_code,label,input,option,option_strategy
material,Material,select,Cotton;Linen;Wool,replace
```
```bash
bin/magento catalog:product:attributes:import imports/material.csv --behavior update
```

**4) Delete attribute sets by name**
```csv
attribute_set
Legacy Set;Obsolete Set
```
```bash
bin/magento catalog:product:attributes:import imports/sets.csv --type attribute-set --behavior delete
```

## Notes
- Lists inside cells are **semicolon-separated** (e.g., `Red;Green;Blue`).  
- For `multiselect`, the backend is set automatically; defaults can be **single** or **semicolon-separated**.  
- Changing an attribute’s `input` type is allowed but warned in output; ensure data compatibility.  
- Verify results in **Admin → Stores → Attributes → Product** and in assigned **Attribute Sets**.

## Uninstall
```bash
bin/magento module:disable AimaneCouissi_CatalogProductAttributesImport
composer remove aimanecouissi/module-catalog-product-attributes-import
bin/magento setup:upgrade
bin/magento cache:flush
```

## License
[MIT](LICENSE)
