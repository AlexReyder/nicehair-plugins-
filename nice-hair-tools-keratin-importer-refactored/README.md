# Nice Hair Tools & Keratin Importer — refactored structure

Плагин разнесён по файлам без переписывания бизнес-логики с нуля.

Главная цель рефакторинга: отделить точки входа WordPress, админку, XLSX, парсинг, медиа-логику и WooCommerce-импорт друг от друга, сохранив совместимость существующих классов `NH_TKI_Plugin`, `NH_TKI_Importer`, `NH_TKI_XLSX_Reader`, `NH_TKI_XLSX_Writer`.

## Основные зоны

- `src/Plugin.php` — регистрация WordPress-хуков.
- `src/Admin` — админская страница, контроллеры запросов и отчёт.
- `src/Import` — orchestration импорта, storage batch-run, source resolver.
- `src/Excel` — чтение, запись, шаблоны и экспорт XLSX.
- `src/Parsing` — определение листов, нормализация заголовков, парсеры строк.
- `src/Media` — ZIP, сопоставление изображений, обработка и attachment-логика.
- `src/WooCommerce` — категории, атрибуты, meta и импортёры конкретных типов товаров.
- `assets/admin/batch-runner.js` — JS batch-импорта, вынесенный из PHP.

## Примечание

Для минимизации риска логика сохранена как набор PHP traits. Это даёт структуру по зонам ответственности без масштабного переписывания алгоритмов импорта.

## Keratin template dropdown

The Keratin XLSX template now adds a dropdown validation for the `Подкатегория` column on the `Данные` sheet.
Options are generated from current WooCommerce child categories of the `Keratin` product category. If the category tree is not available, the template falls back to `Italian Gel Keratin` and `Pigmented Keratin`.


## 0.1.2

- Ready to Install template: columns `Тип наращивания`, `Качество волос`, `Цветовая группа`, `Текстура`, `Длина` now use dropdown lists based on WooCommerce attribute terms.
- Ready to Install template: `Featured` was renamed to `Hot`.
- Ready to Install template: `В наличии` and `Hot` now use `да` / `нет` dropdown values.
- Ready to Install template: `Статус` column was removed; imported products default to `publish`.
- Ready to Install parser keeps backward compatibility with the old `Featured` column and now accepts both `yes/no` and `да/нет` boolean values.


## 0.1.3

- Exclusive Hair pricing now uses the Variant B model.
- `Базовая цена лота` is the current selling price for Bulk and is synchronized to WooCommerce regular price.
- WooCommerce sale price is cleared and is not used for Exclusive Hair.
- The optional `Цена до скидки` column stores `nh_compare_at_lot_price` for frontend strike-through pricing.
- Old files with `Цена без скидки` are accepted as a fallback for `Цена до скидки`; `Цена со скидкой` is ignored for Exclusive Hair.


## 0.1.4

- Exclusive Hair template: columns `Текстура`, `Цветовая группа`, `Длина` now use dropdown lists based on WooCommerce attribute terms.
- Exclusive Hair template: `Featured` was renamed to `Hot`.
- Exclusive Hair template: `В наличии` and `Hot` now use `да` / `нет` dropdown values.
- Exclusive Hair template: `Статус` and `Фото` columns were removed.
- Exclusive Hair parser defaults imported products to `publish` and keeps backward compatibility with older files containing `Featured`, `Фото`, or `Статус` columns.
