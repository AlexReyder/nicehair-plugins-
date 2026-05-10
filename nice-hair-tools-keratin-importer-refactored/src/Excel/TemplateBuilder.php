<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

trait NH_TKI_TemplateBuilderTrait
{
    public function template_family(string $family): array
    {
        $this->bootstrap_import_environment();
        $family = in_array($family, ['tools', 'keratin', 'ready_to_install', 'exclusive_hair', 'custom_hair'], true) ? $family : 'tools';
        $sheets = match ($family) {
            'keratin' => $this->build_keratin_template_sheets(),
            'ready_to_install' => $this->build_ready_to_install_template_sheets(),
            'exclusive_hair' => $this->build_exclusive_hair_template_sheets(),
            'custom_hair' => $this->build_custom_hair_template_sheets(),
            default => $this->build_tools_template_sheets(),
        };

        return [
            'filename' => sprintf('nice-hair-%s-template.xlsx', $family),
            'content' => NH_TKI_XLSX_Writer::write_workbook($sheets),
        ];
    }
    private function build_tools_template_sheets(): array
    {
        return $this->build_template_sheets('Tools', [
            'Фото в ZIP ищется автоматически по артикулу товара: например TOOL-001-01.jpg, TOOL-001-02.jpg.',
            'Цены можно писать как 25 или 25$. Колонку "Цена со скидкой" можно оставить пустой.',
        ], [
            'Название товара',
            'Описание товара',
            'Артикул',
            'Цена без скидки',
            'Цена со скидкой',
            'Ссылка на видео',
        ]);
    }
    private function build_keratin_template_sheets(): array
    {
        $subcategoryOptions = $this->get_keratin_subcategory_options();
        $sheets = $this->build_template_sheets('Keratin', [
            'Подкатегория выбирается из dropdown-списка. Список формируется из текущих дочерних категорий WooCommerce внутри категории Keratin.',
            'Артикул, Вес упаковки, Цена без скидки и Цена со скидкой можно заполнять списками через запятую в одном порядке.',
            'Фото в ZIP ищется по SKU вариации: например KER-001-10G-01.jpg, KER-001-10G-02.jpg.',
        ], [
            'Подкатегория',
            'Название товара',
            'Описание товара',
            'Артикул',
            'Вес упаковки',
            'Цена без скидки',
            'Цена со скидкой',
            'Ссылка на видео',
        ]);

        if ($subcategoryOptions !== []) {
            $dictionarySheetName = 'Справочник Keratin';
            $definedName = 'nh_keratin_subcategories';
            $lastOptionRow = count($subcategoryOptions) + 1;

            $sheets[0]['data_validations'] = [
                [
                    'type' => 'list',
                    'sqref' => 'A2:A1000',
                    'formula1' => $definedName,
                    'allow_blank' => true,
                    'show_error_message' => true,
                    'error_title' => 'Некорректная подкатегория',
                    'error' => 'Выберите подкатегорию из выпадающего списка.',
                ],
            ];

            $sheets[] = [
                'name' => $dictionarySheetName,
                'hidden' => true,
                'rows' => array_merge(
                    [['Подкатегории Keratin']],
                    array_map(static fn (string $name): array => [$name], $subcategoryOptions)
                ),
                'defined_names' => [
                    [
                        'name' => $definedName,
                        'ref' => sprintf(
                            "'%s'!\$A\$2:\$A\$%d",
                            str_replace("'", "''", $dictionarySheetName),
                            $lastOptionRow
                        ),
                    ],
                ],
            ];
        }

        return $sheets;
    }
    private function build_ready_to_install_template_sheets(): array
    {
        $attributeLists = [
            'extension_type' => [
                'label' => 'Тип наращивания',
                'taxonomy' => 'pa_extension_type',
                'range' => 'F2:F1000',
                'defined_name' => 'nh_ready_extension_type',
                'error_title' => 'Некорректный тип наращивания',
                'error' => 'Выберите тип наращивания из выпадающего списка.',
            ],
            'hair_quality' => [
                'label' => 'Качество волос',
                'taxonomy' => 'pa_hair_quality',
                'range' => 'G2:G1000',
                'defined_name' => 'nh_ready_hair_quality',
                'error_title' => 'Некорректное качество волос',
                'error' => 'Выберите качество волос из выпадающего списка.',
            ],
            'color_group' => [
                'label' => 'Цветовая группа',
                'taxonomy' => 'pa_color_group',
                'range' => 'H2:H1000',
                'defined_name' => 'nh_ready_color_group',
                'error_title' => 'Некорректная цветовая группа',
                'error' => 'Выберите цветовую группу из выпадающего списка.',
            ],
            'texture' => [
                'label' => 'Текстура',
                'taxonomy' => 'pa_texture',
                'range' => 'I2:I1000',
                'defined_name' => 'nh_ready_texture',
                'error_title' => 'Некорректная текстура',
                'error' => 'Выберите текстуру из выпадающего списка.',
            ],
            'length' => [
                'label' => 'Длина',
                'taxonomy' => 'pa_length',
                'range' => 'J2:J1000',
                'defined_name' => 'nh_ready_length',
                'error_title' => 'Некорректная длина',
                'error' => 'Выберите длину из выпадающего списка.',
            ],
        ];

        $sheets = $this->build_template_sheets('Ready to Install', [
            'Обязательные атрибуты: Тип наращивания, Качество волос, Цветовая группа.',
            'Тип наращивания, Качество волос, Цветовая группа, Текстура и Длина выбираются из dropdown-списков. Списки формируются из актуальных WooCommerce-атрибутов.',
            'В наличии и Hot выбираются из dropdown-списка: да или нет.',
            'Статус в шаблоне не заполняется: товары импортируются в статусе publish.',
            'Фото: перечислите имена файлов из ZIP через запятую; если пусто, будет поиск по SKU-prefix.',
        ], [
            'Название товара',
            'Описание товара',
            'Артикул',
            'Цена без скидки',
            'Цена со скидкой',
            'Тип наращивания',
            'Качество волос',
            'Цветовая группа',
            'Текстура',
            'Длина',
            'В наличии',
            'Hot',
            'Ссылка на видео',
            'Фото',
        ]);

        $dataValidations = [];
        $dictionaryLists = [
            'nh_ready_yes_no' => [
                'label' => 'Да / Нет',
                'values' => ['да', 'нет'],
            ],
        ];

        foreach ($attributeLists as $listConfig) {
            $options = $this->get_product_attribute_term_options((string) $listConfig['taxonomy']);

            if ($options === []) {
                continue;
            }

            $definedName = (string) $listConfig['defined_name'];
            $dictionaryLists[$definedName] = [
                'label' => (string) $listConfig['label'],
                'values' => $options,
            ];
            $dataValidations[] = [
                'type' => 'list',
                'sqref' => (string) $listConfig['range'],
                'formula1' => $definedName,
                'allow_blank' => true,
                'show_error_message' => true,
                'error_title' => (string) $listConfig['error_title'],
                'error' => (string) $listConfig['error'],
            ];
        }

        foreach (['K2:K1000', 'L2:L1000'] as $yesNoRange) {
            $dataValidations[] = [
                'type' => 'list',
                'sqref' => $yesNoRange,
                'formula1' => 'nh_ready_yes_no',
                'allow_blank' => true,
                'show_error_message' => true,
                'error_title' => 'Некорректное значение',
                'error' => 'Выберите значение да или нет.',
            ];
        }

        if ($dataValidations !== []) {
            $sheets[0]['data_validations'] = $dataValidations;
        }

        $sheets[] = $this->build_ready_to_install_dictionary_sheet($dictionaryLists);

        return $sheets;
    }
    private function build_exclusive_hair_template_sheets(): array
    {
        $attributeLists = [
            'texture' => [
                'label' => 'Текстура',
                'taxonomy' => 'pa_texture',
                'range' => 'G2:G1000',
                'defined_name' => 'nh_exclusive_texture',
                'error_title' => 'Некорректная текстура',
                'error' => 'Выберите текстуру из выпадающего списка.',
            ],
            'color_group' => [
                'label' => 'Цветовая группа',
                'taxonomy' => 'pa_color_group',
                'range' => 'H2:H1000',
                'defined_name' => 'nh_exclusive_color_group',
                'error_title' => 'Некорректная цветовая группа',
                'error' => 'Выберите цветовую группу из выпадающего списка.',
            ],
            'length' => [
                'label' => 'Длина',
                'taxonomy' => 'pa_length',
                'range' => 'I2:I1000',
                'defined_name' => 'nh_exclusive_length',
                'error_title' => 'Некорректная длина',
                'error' => 'Выберите длину из выпадающего списка.',
            ],
        ];

        $sheets = $this->build_template_sheets('Exclusive Hair', [
            'Обязательные поля: Базовая цена лота, Вес, гр, Текстура, Цветовая группа, Длина.',
            'Базовая цена лота — текущая цена продажи Bulk. Это значение сохраняется в ACF и становится WooCommerce sale price, если указана Цена до скидки.',
            'Цена до скидки — необязательная старая цена лота для зачёркнутой цены. Если заполнена и больше Базовой цены лота, она становится WooCommerce regular price.',
            'Текстура, Цветовая группа и Длина выбираются из dropdown-списков. Списки формируются из актуальных WooCommerce-атрибутов.',
            'В наличии и Hot выбираются из dropdown-списка: да или нет.',
            'Статус в шаблоне не заполняется: товары импортируются в статусе publish.',
            'Фото не заполняются в таблице: импортер ищет файлы в ZIP автоматически по SKU-prefix, например EXH-001-01.jpg, EXH-001-02.jpg.',
        ], [
            'Название товара',
            'Описание товара',
            'Артикул',
            'Базовая цена лота',
            'Цена до скидки',
            'Вес, гр',
            'Текстура',
            'Цветовая группа',
            'Длина',
            'В наличии',
            'Hot',
            'Ссылка на видео',
        ]);

        $dataValidations = [];
        $dictionaryLists = [
            'nh_exclusive_yes_no' => [
                'label' => 'Да / Нет',
                'values' => ['да', 'нет'],
            ],
        ];

        foreach ($attributeLists as $listConfig) {
            $options = $this->get_product_attribute_term_options((string) $listConfig['taxonomy']);

            if ($options === []) {
                continue;
            }

            $definedName = (string) $listConfig['defined_name'];
            $dictionaryLists[$definedName] = [
                'label' => (string) $listConfig['label'],
                'values' => $options,
            ];
            $dataValidations[] = [
                'type' => 'list',
                'sqref' => (string) $listConfig['range'],
                'formula1' => $definedName,
                'allow_blank' => true,
                'show_error_message' => true,
                'error_title' => (string) $listConfig['error_title'],
                'error' => (string) $listConfig['error'],
            ];
        }

        foreach (['J2:J1000', 'K2:K1000'] as $yesNoRange) {
            $dataValidations[] = [
                'type' => 'list',
                'sqref' => $yesNoRange,
                'formula1' => 'nh_exclusive_yes_no',
                'allow_blank' => true,
                'show_error_message' => true,
                'error_title' => 'Некорректное значение',
                'error' => 'Выберите значение да или нет.',
            ];
        }

        if ($dataValidations !== []) {
            $sheets[0]['data_validations'] = $dataValidations;
        }

        $sheets[] = $this->build_exclusive_hair_dictionary_sheet($dictionaryLists);

        return $sheets;
    }
    private function build_custom_hair_template_sheets(): array
    {
        $attributeLists = [
            'length' => [
                'label' => 'Доступные длины',
                'taxonomy' => 'pa_length',
                'range' => 'F2:F1000',
                'defined_name' => 'nh_custom_lengths',
                'error_title' => 'Некорректная длина',
                'error' => 'Выберите длину из выпадающего списка или перечислите несколько значений через запятую.',
            ],
            'hair_quality' => [
                'label' => 'Доступные качества',
                'taxonomy' => 'pa_hair_quality',
                'range' => 'G2:G1000',
                'defined_name' => 'nh_custom_qualities',
                'error_title' => 'Некорректное качество волос',
                'error' => 'Выберите качество из выпадающего списка или перечислите несколько значений через запятую.',
            ],
            'texture' => [
                'label' => 'Доступные текстуры',
                'taxonomy' => 'pa_texture',
                'range' => 'H2:H1000',
                'defined_name' => 'nh_custom_textures',
                'error_title' => 'Некорректная текстура',
                'error' => 'Выберите текстуру из выпадающего списка или перечислите несколько значений через запятую.',
            ],
        ];

        $sheets = $this->build_template_sheets('Custom Hair', [
            'Один товар Custom Hair = один тип наращивания / product form. Название товара и есть этот тип, например Bulk или Genius weft.',
            'Отдельная колонка "Тип наращивания" больше не используется: название товара создаёт/обновляет term в pa_extension_type.',
            'Доступные длины, качества и текстуры выбираются из dropdown-списков на основе актуальных WooCommerce-атрибутов. Для нескольких значений перечислите их через запятую.',
            'Цветовые опции выбираются из dropdown-списка на основе активных цветов из ACF-страницы "Цвета Custom Hair". Для нескольких цветов перечислите их через запятую.',
            'Цветовые опции необязательны: если оставить пусто, новый товар создастся без выбранных глобальных цветов, а у существующего товара выбранные цвета не изменятся.',
            'В наличии и Hot выбираются из dropdown-списка: да или нет.',
            'Статус в шаблоне не заполняется: товары импортируются в статусе publish.',
            'Фото не заполняются в таблице: импортер ищет файлы в ZIP автоматически по SKU-prefix, например CUSTOM-001-01.jpg, CUSTOM-001-02.jpg.',
        ], [
            'Название товара',
            'Описание товара',
            'Артикул',
            'Цена без скидки',
            'Цена со скидкой',
            'Доступные длины',
            'Доступные качества',
            'Доступные текстуры',
            'Мин. вес, гр',
            'Шаг веса, гр',
            'Вес по умолчанию, гр',
            'Цветовые опции',
            'В наличии',
            'Hot',
            'Ссылка на видео',
        ]);

        $dataValidations = [];
        $dictionaryLists = [
            'nh_custom_yes_no' => [
                'label' => 'Да / Нет',
                'values' => ['да', 'нет'],
            ],
        ];

        $colorOptions = $this->get_custom_hair_global_color_dropdown_options();

        if ($colorOptions !== []) {
            $dictionaryLists['nh_custom_colors'] = [
                'label' => 'Цветовые опции',
                'values' => $colorOptions,
            ];
            $dataValidations[] = [
                'type' => 'list',
                'sqref' => 'L2:L1000',
                'formula1' => 'nh_custom_colors',
                'allow_blank' => true,
                'show_error_message' => false,
                'error_style' => 'warning',
                'error_title' => 'Некорректная цветовая опция',
                'error' => 'Выберите цвет из выпадающего списка или перечислите несколько цветов через запятую.',
            ];
        }

        foreach ($attributeLists as $listConfig) {
            $options = $this->get_product_attribute_term_options((string) $listConfig['taxonomy']);

            if ($options === []) {
                continue;
            }

            $definedName = (string) $listConfig['defined_name'];
            $dictionaryLists[$definedName] = [
                'label' => (string) $listConfig['label'],
                'values' => $options,
            ];
            $dataValidations[] = [
                'type' => 'list',
                'sqref' => (string) $listConfig['range'],
                'formula1' => $definedName,
                'allow_blank' => true,
                'show_error_message' => false,
                'error_style' => 'warning',
                'error_title' => (string) $listConfig['error_title'],
                'error' => (string) $listConfig['error'],
            ];
        }

        foreach (['M2:M1000', 'N2:N1000'] as $yesNoRange) {
            $dataValidations[] = [
                'type' => 'list',
                'sqref' => $yesNoRange,
                'formula1' => 'nh_custom_yes_no',
                'allow_blank' => true,
                'show_error_message' => true,
                'error_title' => 'Некорректное значение',
                'error' => 'Выберите значение да или нет.',
            ];
        }

        if ($dataValidations !== []) {
            $sheets[0]['data_validations'] = $dataValidations;
        }

        $sheets[] = $this->build_custom_hair_dictionary_sheet($dictionaryLists);

        return $sheets;
    }
    private function get_product_attribute_term_options(string $taxonomy): array
    {
        if (! function_exists('get_terms') || ! taxonomy_exists($taxonomy)) {
            return [];
        }

        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ]);

        if (! is_array($terms) || is_wp_error($terms)) {
            return [];
        }

        $options = [];

        foreach ($terms as $term) {
            if ($term instanceof WP_Term) {
                $name = trim((string) $term->name);

                if ($name !== '') {
                    $options[$name] = $name;
                }
            }
        }

        return array_values($options);
    }

    private function build_ready_to_install_dictionary_sheet(array $dictionaryLists): array
    {
        $dictionarySheetName = 'Справочник Ready';
        $columns = array_values($dictionaryLists);
        $definedNames = [];
        $maxRows = 1;

        foreach ($columns as $column) {
            $maxRows = max($maxRows, count((array) ($column['values'] ?? [])) + 1);
        }

        $rows = [];

        for ($rowIndex = 0; $rowIndex < $maxRows; $rowIndex++) {
            $row = [];

            foreach ($columns as $column) {
                if ($rowIndex === 0) {
                    $row[] = (string) ($column['label'] ?? '');
                } else {
                    $values = array_values((array) ($column['values'] ?? []));
                    $row[] = (string) ($values[$rowIndex - 1] ?? '');
                }
            }

            $rows[] = $row;
        }

        foreach (array_keys($dictionaryLists) as $columnIndex => $definedName) {
            $values = array_values((array) ($dictionaryLists[$definedName]['values'] ?? []));

            if ($values === []) {
                continue;
            }

            $definedNames[] = [
                'name' => (string) $definedName,
                'ref' => sprintf(
                    "'%s'!\$%s\$2:\$%s\$%d",
                    str_replace("'", "''", $dictionarySheetName),
                    $this->xlsx_column_name($columnIndex),
                    $this->xlsx_column_name($columnIndex),
                    count($values) + 1
                ),
            ];
        }

        return [
            'name' => $dictionarySheetName,
            'hidden' => true,
            'rows' => $rows,
            'defined_names' => $definedNames,
        ];
    }

    private function build_exclusive_hair_dictionary_sheet(array $dictionaryLists): array
    {
        $dictionarySheetName = 'Справочник Exclusive';
        $columns = array_values($dictionaryLists);
        $definedNames = [];
        $maxRows = 1;

        foreach ($columns as $column) {
            $maxRows = max($maxRows, count((array) ($column['values'] ?? [])) + 1);
        }

        $rows = [];

        for ($rowIndex = 0; $rowIndex < $maxRows; $rowIndex++) {
            $row = [];

            foreach ($columns as $column) {
                if ($rowIndex === 0) {
                    $row[] = (string) ($column['label'] ?? '');
                } else {
                    $values = array_values((array) ($column['values'] ?? []));
                    $row[] = (string) ($values[$rowIndex - 1] ?? '');
                }
            }

            $rows[] = $row;
        }

        foreach (array_keys($dictionaryLists) as $columnIndex => $definedName) {
            $values = array_values((array) ($dictionaryLists[$definedName]['values'] ?? []));

            if ($values === []) {
                continue;
            }

            $definedNames[] = [
                'name' => (string) $definedName,
                'ref' => sprintf(
                    "'%s'!\$%s\$2:\$%s\$%d",
                    str_replace("'", "''", $dictionarySheetName),
                    $this->xlsx_column_name($columnIndex),
                    $this->xlsx_column_name($columnIndex),
                    count($values) + 1
                ),
            ];
        }

        return [
            'name' => $dictionarySheetName,
            'hidden' => true,
            'rows' => $rows,
            'defined_names' => $definedNames,
        ];
    }

    private function build_custom_hair_dictionary_sheet(array $dictionaryLists): array
    {
        $dictionarySheetName = 'Справочник Custom';
        $columns = array_values($dictionaryLists);
        $definedNames = [];
        $maxRows = 1;

        foreach ($columns as $column) {
            $maxRows = max($maxRows, count((array) ($column['values'] ?? [])) + 1);
        }

        $rows = [];

        for ($rowIndex = 0; $rowIndex < $maxRows; $rowIndex++) {
            $row = [];

            foreach ($columns as $column) {
                if ($rowIndex === 0) {
                    $row[] = (string) ($column['label'] ?? '');
                } else {
                    $values = array_values((array) ($column['values'] ?? []));
                    $row[] = (string) ($values[$rowIndex - 1] ?? '');
                }
            }

            $rows[] = $row;
        }

        foreach (array_keys($dictionaryLists) as $columnIndex => $definedName) {
            $values = array_values((array) ($dictionaryLists[$definedName]['values'] ?? []));

            if ($values === []) {
                continue;
            }

            $definedNames[] = [
                'name' => (string) $definedName,
                'ref' => sprintf(
                    "'%s'!\$%s\$2:\$%s\$%d",
                    str_replace("'", "''", $dictionarySheetName),
                    $this->xlsx_column_name($columnIndex),
                    $this->xlsx_column_name($columnIndex),
                    count($values) + 1
                ),
            ];
        }

        return [
            'name' => $dictionarySheetName,
            'hidden' => true,
            'rows' => $rows,
            'defined_names' => $definedNames,
        ];
    }

    private function xlsx_column_name(int $columnIndex): string
    {
        $column = '';
        $index = $columnIndex + 1;

        while ($index > 0) {
            $remainder = ($index - 1) % 26;
            $column = chr(65 + $remainder) . $column;
            $index = intdiv($index - 1, 26);
        }

        return $column;
    }

    private function build_template_sheets(string $familyLabel, array $notes, array $headers): array
    {
        return [
            [
                'name' => 'Данные',
                'rows' => [$headers],
            ],
            [
                'name' => 'Правила',
                'rows' => $this->build_template_rules_rows($familyLabel, $notes),
            ],
        ];
    }

    private function get_keratin_subcategory_options(): array
    {
        $fallback = ['Italian Gel Keratin', 'Pigmented Keratin'];

        if (! function_exists('get_terms') || ! taxonomy_exists('product_cat')) {
            return $fallback;
        }

        $parentId = $this->find_product_category_id_by_name_or_slug(NH_TKI_Plugin::KERATIN_CATEGORY);

        if ($parentId <= 0) {
            return $fallback;
        }

        $terms = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'parent' => $parentId,
            'orderby' => 'name',
            'order' => 'ASC',
        ]);

        if (! is_array($terms) || is_wp_error($terms)) {
            return $fallback;
        }

        $options = [];

        foreach ($terms as $term) {
            if ($term instanceof WP_Term) {
                $name = trim((string) $term->name);

                if ($name !== '') {
                    $options[$name] = $name;
                }
            }
        }

        return array_values($options) ?: $fallback;
    }

    private function find_product_category_id_by_name_or_slug(string $name, int $parentId = 0): int
    {
        if (! function_exists('get_terms') || ! taxonomy_exists('product_cat')) {
            return 0;
        }

        $terms = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'name' => $name,
            'parent' => $parentId,
            'number' => 1,
        ]);

        if (is_array($terms) && isset($terms[0]) && $terms[0] instanceof WP_Term) {
            return (int) $terms[0]->term_id;
        }

        $slug = sanitize_title($name);

        if ($slug === '') {
            return 0;
        }

        $terms = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'slug' => $slug,
            'parent' => $parentId,
            'number' => 1,
        ]);

        if (is_array($terms) && isset($terms[0]) && $terms[0] instanceof WP_Term) {
            return (int) $terms[0]->term_id;
        }

        return 0;
    }
    private function build_template_rules_rows(string $familyLabel, array $notes): array
    {
        $rows = [
            ['Шаблон импорта Nice Hair: ' . $familyLabel],
            ['Заполняйте лист "Данные". Названия колонок на листе "Данные" не менять.'],
            ['Лист "Правила" можно оставить в XLSX при загрузке: импортер найдет лист с нужными заголовками.'],
            [],
            ['Правила категории'],
        ];

        foreach ($notes as $note) {
            $rows[] = [(string) $note];
        }

        $rows[] = [];
        $rows[] = ['Общие поля'];
        $rows[] = ['Цены можно писать как 25 или 25$. Пустая цена со скидкой означает, что скидки нет.'];
        $rows[] = ['Если есть колонка "В наличии": yes/да или пусто = товар в наличии, no/нет = нет в наличии.'];
        $rows[] = ['Если есть колонка "Featured" или "Hot": yes/да = отметить товар, no/нет или пусто = не отмечать.'];
        $rows[] = ['Если есть колонка "Статус": publish = опубликовать, draft = сохранить как черновик. Пустое значение = publish.'];
        $rows[] = ['Если есть колонка "Ссылка на видео": URL сохранится в поле nh_product_video_url.'];
        $rows[] = [];
        $rows[] = ['Фотографии'];
        $rows[] = ['Загружайте фотографии одним ZIP-архивом вместе с XLSX. Подпапки допустимы, но не обязательны.'];
        $rows[] = ['Рекомендуемое имя файла: [артикул/SKU]-[номер].[jpg/png/webp], например 0001-01.jpg, 0001-02.jpg.'];
        $rows[] = ['Файл с номером -01 будет первым: он станет главным фото товара и первым фото в карточке.'];
        $rows[] = ['Номер пишите с ведущим нулем: -01, -02, -03.'];
        $rows[] = ['Для Tools, Ready to Install, Exclusive Hair и Custom Hair используйте артикул товара.'];
        $rows[] = ['Для Keratin используйте SKU вариации, например KER-001-10G-01.jpg, а не общий group key.'];
        $rows[] = ['Если в шаблоне есть колонка "Фото", можно явно перечислить файлы через запятую. Порядок в колонке будет порядком фотографий.'];
        $rows[] = ['Если колонка "Фото" пустая или ее нет, импортер ищет файлы по префиксу SKU.'];
        $rows[] = ['В ZIP можно хранить файлы в папке категории, например Tools/0001-01.jpg.'];

        return $rows;
    }
}
