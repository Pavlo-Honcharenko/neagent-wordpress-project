<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ASPO_Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'scripts' ] );
        add_action( 'wp_ajax_aspo_run_import', [ $this, 'run_import' ] );
        
        // Cron task hook
        add_action( 'aspo_cron_import_event', [ $this, 'execute_import' ] );
        
        // Registration of the schedule upon activation (can be exported to the main file of the plugin)
        if ( ! wp_next_scheduled( 'aspo_cron_import_event' ) ) {
            wp_schedule_event( time(), 'hourly', 'aspo_cron_import_event' );
        }
    }

    public function menu() {
        add_menu_page(
            'ASPO',
            'ASPO',
            'manage_options',
            'aspo',
            [ $this, 'page' ]
        );
    }

    public function page() {
        ?>
        <div class="wrap">
            <h1>ASPO Import (DEV)</h1>
            <p>Автоматичний імпорт налаштовано на запуск <strong>щогодини</strong>.</p>
            <button class="button button-primary" id="aspo-run">
                Run import manually
            </button>
            <pre id="aspo-result"></pre>
        </div>
        <?php
    }

    public function scripts( $hook ) {
        if ( $hook !== 'toplevel_page_aspo' ) {
            return;
        }

        wp_enqueue_script(
            'aspo-admin',
            ASPO_URL . 'admin/aspo-admin.js',
            [ 'jquery' ],
            '1.0',
            true
        );

        wp_localize_script( 'aspo-admin', 'ASPO', [
            'ajax'  => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'aspo_nonce' ),
        ]);
    }

    /**
     * Run via AJAX (Button)
     */
    public function run_import() {
        check_ajax_referer( 'aspo_nonce' );
        
        $this->execute_import();

        wp_send_json_success( 'Import executed. Check log file.' );
    }

    /**
     * Universal execution method
     */
    public function execute_import() {
        $importer = new ASPO_Importer();
        $importer->run();
    }
}