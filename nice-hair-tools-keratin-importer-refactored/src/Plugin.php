<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

final class NH_TKI_Plugin
{

    public const PAGE_SLUG = 'nice-hair-tools-keratin-importer';
    public const LEGACY_PARENT_SLUG = 'admin.php';
    public const PAGE_PARENT_SLUG = 'tools.php';
    public const AJAX_ACTION = 'nh_tki_process_run';
    public const EXPORT_ACTION = 'nh_tki_export';
    public const TEMPLATE_ACTION = 'nh_tki_template';
    public const NONCE_ACTION = 'nh_tki_run';
    public const META_SOURCE_FAMILY = '_nh_tki_source_family';
    public const META_GROUP_KEY = '_nh_tki_import_group_key';
    public const META_SOURCE_SKU = '_nh_tki_source_sku';
    public const META_ASSET_KEY = '_nh_tki_asset_key';
    public const META_ASSET_CONTEXT = '_nh_tki_asset_context';
    public const META_ASSET_SOURCE_HASH = '_nh_tki_asset_source_hash';
    public const META_ASSET_PIPELINE_VERSION = '_nh_tki_asset_pipeline_version';
    public const VIDEO_FIELD_KEY = 'field_nh_product_video_url';
    public const WEIGHT_ATTRIBUTE_SLUG = 'weight';
    public const WEIGHT_TAXONOMY = 'pa_weight';
    public const TOOLS_CATEGORY = 'Tools';
    public const KERATIN_CATEGORY = 'Keratin';
    public const READY_CATEGORY = 'Ready to Install';
    public const EXCLUSIVE_CATEGORY = 'Exclusive Hair';
    public const CUSTOM_HAIR_CATEGORY = 'Custom Hair';


    use NH_TKI_AdminUrls;
    use NH_TKI_AdminPage;
    use NH_TKI_AjaxBatchController;
    use NH_TKI_ExportController;
    use NH_TKI_TemplateController;
    use NH_TKI_AdminReportView;

    public static function init(): void
    {
        add_action('admin_init', [self::class, 'maybe_redirect_legacy_admin_url']);
        add_action('admin_menu', [self::class, 'register_admin_page']);
        add_action('wp_ajax_' . self::AJAX_ACTION, [self::class, 'handle_process_run_ajax']);
        add_action('admin_post_' . self::EXPORT_ACTION, [self::class, 'handle_export_request']);
        add_action('admin_post_' . self::TEMPLATE_ACTION, [self::class, 'handle_template_request']);
    }
}
