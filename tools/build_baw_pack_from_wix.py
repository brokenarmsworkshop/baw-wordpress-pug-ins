#!/usr/bin/env python3
import csv, hashlib, html, json, re, sys, zipfile
from collections import OrderedDict
from decimal import Decimal
from pathlib import Path


def sku_for(handle):
    value = handle.removeprefix('product_').replace('-', '').upper()
    return 'BAW-WIX-' + value[:16]


def clean(value):
    return html.unescape(value or '').strip()


def description(row):
    parts = [row.get('description', '')]
    for index in range(1, 7):
        title = clean(row.get(f'additionalInfoTitle{index}', ''))
        body = row.get(f'additionalInfoDescription{index}', '').strip()
        if title and body:
            parts.append(f'<h2>{html.escape(title.title())}</h2>{body}')
    return '\n'.join(part for part in parts if part).strip()


def build(source, output):
    with source.open(encoding='utf-8-sig', newline='') as stream:
        rows = list(csv.DictReader(stream))
    groups = OrderedDict()
    for row in rows:
        groups.setdefault(row['handleId'], []).append(row)
    products = []
    for handle, group in groups.items():
        parent = next(row for row in group if row['fieldType'] == 'Product')
        children = [row for row in group if row['fieldType'] == 'Variant']
        parent_sku = sku_for(handle)
        entry = {
            'type': 'variable' if children else 'simple',
            'sku': parent_sku,
            'name': clean(parent['name']),
            'price': parent['price'] or '0',
            'short_description': '',
            'description': description(parent),
            'image': '',
            'elementor_json': '',
            'source_meta': {
                '_baw_wix_handle': handle,
                '_baw_wix_inventory': parent['inventory'],
                '_baw_wix_image_refs': parent['productImageUrl'],
                '_baw_wix_original_sku': parent['sku'],
                '_baw_wix_original_weight': parent['weight'],
                '_baw_import_review_required': '1',
            },
        }
        if children:
            option_columns = []
            for index in range(1, 7):
                option_name = clean(parent.get(f'productOptionName{index}', ''))
                if option_name:
                    option_columns.append((option_name, index))
            attributes = []
            for option_name, index in option_columns:
                values = list(dict.fromkeys(clean(row[f'productOptionDescription{index}']) for row in children))
                attributes.append({'name': option_name, 'values': values})
            entry['attributes'] = attributes
            variations = []
            base = Decimal(parent['price'] or '0')
            for child in children:
                values = {name: clean(child[f'productOptionDescription{index}']) for name, index in option_columns}
                key = '\0'.join(values.values()).encode('utf-8')
                variations.append({
                    'sku': parent_sku + '-V' + hashlib.sha256(key).hexdigest()[:10].upper(),
                    'price': f'{base + Decimal(child["surcharge"] or "0"):.2f}',
                    'stock_status': 'outofstock',
                    'attributes': values,
                    'source_inventory': child['inventory'],
                    'source_weight': child['weight'],
                })
            entry['variations'] = variations
        products.append(entry)
    manifest = {
        'schema_version': 2,
        'pack_id': 'baw-wix-catalogue-2026-09-24',
        'source': source.name,
        'notes': 'Import de reprise. Brouillons cachés, variations hors stock, images à fournir.',
        'products': products,
    }
    output.parent.mkdir(parents=True, exist_ok=True)
    raw = json.dumps(manifest, ensure_ascii=False, indent=2).encode('utf-8')
    with zipfile.ZipFile(output, 'w', zipfile.ZIP_DEFLATED) as archive:
        archive.writestr('manifest.json', raw)
        archive.writestr('README.txt', 'Pack BAW v2 : 30 produits Wix, dont 8 variables et 57 variations. Tous les parents sont importés en brouillon caché. Les variations sont hors stock. Les images ne sont pas incluses.\n')
    print(f'{len(products)} produits, {sum(len(p.get("variations", [])) for p in products)} variations, manifeste {len(raw)} octets')


if __name__ == '__main__':
    build(Path(sys.argv[1]), Path(sys.argv[2]))
