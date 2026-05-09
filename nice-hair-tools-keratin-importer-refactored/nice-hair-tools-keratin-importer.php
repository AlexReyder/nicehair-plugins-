<?php
/**
 * Plugin Name: Nice Hair Tools & Keratin Importer
 * Description: Imports Tools, Keratin, Ready to Install, Exclusive Hair and Custom Hair products for Nice Hair from XLSX + ZIP sources.
 * Version: 0.1.4
 * Author: Nice Hair
 * Text Domain: nice-hair-tools-keratin-importer
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('NH_TKI_PLUGIN_FILE', __FILE__);
define('NH_TKI_PLUGIN_VERSION', '0.1.4');

$nh_tki_files = [
    'src/Config/PluginConfig.php',
    'src/Config/ImportFamilies.php',
    'src/Config/ProductMetaKeys.php',

    'src/Support/FileSystem.php',
    'src/Support/BytesFormatter.php',
    'src/Support/Sanitizer.php',
    'src/Support/EnvironmentGuard.php',

    'src/Admin/AdminUrls.php',
    'src/Admin/AdminPage.php',
    'src/Admin/Controllers/AjaxBatchController.php',
    'src/Admin/Controllers/ExportController.php',
    'src/Admin/Controllers/TemplateController.php',
    'src/Admin/Views/AdminReportView.php',

    'src/Import/Source/SourceFileResolver.php',
    'src/Import/ImportReport.php',
    'src/Import/ImportRunStorage.php',
    'src/Excel/XlsxReader.php',
    'src/Excel/XlsxWriter.php',
    'src/Excel/TemplateBuilder.php',
    'src/Excel/ExportBuilder.php',

    'src/Parsing/HeaderNormalizer.php',
    'src/Parsing/WorkbookNormalizer.php',
    'src/Parsing/RowParsers/ToolsRowParser.php',
    'src/Parsing/RowParsers/ReadyToInstallRowParser.php',
    'src/Parsing/RowParsers/ExclusiveHairRowParser.php',
    'src/Parsing/RowParsers/CustomHairRowParser.php',
    'src/Parsing/RowParsers/KeratinRowParser.php',

    'src/Media/PhotoIndexBuilder.php',
    'src/Media/ImageEntryResolver.php',
    'src/Media/ImageProcessor.php',
    'src/Media/AttachmentImporter.php',
    'src/Media/AttachmentCleanup.php',

    'src/WooCommerce/ProductRepository.php',
    'src/WooCommerce/CategoryService.php',
    'src/WooCommerce/AttributeService.php',
    'src/WooCommerce/MetaService.php',
    'src/WooCommerce/ProductImporters/ToolsProductImporter.php',
    'src/WooCommerce/ProductImporters/ReadyToInstallProductImporter.php',
    'src/WooCommerce/ProductImporters/ExclusiveHairProductImporter.php',
    'src/WooCommerce/ProductImporters/CustomHairProductImporter.php',
    'src/WooCommerce/ProductImporters/KeratinProductImporter.php',

    'src/Import/ImportService.php',

    'src/Plugin.php',
];

foreach ($nh_tki_files as $nh_tki_file) {
    require_once plugin_dir_path(__FILE__) . $nh_tki_file;
}

NH_TKI_Plugin::init();
