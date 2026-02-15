<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ASPO_Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'scripts' ] );
        add_action( 'wp_ajax_aspo_run_import', [ $this, 'run_import' ] );
        
        // A hook that causes Cron
        add_action( 'aspo_cron_import_event', [ $this, 'run_cron_import' ] );
    }

    public function menu() {
        add_menu_page('ASPO', 'ASPO', 'manage_options', 'aspo', [ $this, 'page' ]);
    }

    public function page() {
        $offset = get_option('aspo_import_offset', 0);
        ?>
        <div class="wrap">
            <h1>ASPO Import (DEV)</h1>
            <p>Статус крону: <?php echo wp_next_scheduled('aspo_cron_import_event') ? '✅ Активний' : '❌ Неактивний'; ?></p>
            <p><strong>Поточний прогрес (Offset):</strong> <?php echo $offset; ?> оголошень пройдено.</p>
            
            <button class="button button-primary" id="aspo-run">Запустити імпорт вручну</button>
            
            <p><small>Після натискання зачекайте 10-20 секунд і оновіть сторінку, щоб побачити новий Offset.</small></p>
            <pre id="aspo-result" style="background: #eee; padding: 10px; margin-top: 20px;">Тут з'явиться результат після натискання...</pre>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#aspo-run').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('Обробка...');
                $('#aspo-result').text('Імпорт запущено, зачекайте...');

                $.post(ASPO.ajax, {
                    action: 'aspo_run_import',
                    _ajax_nonce: ASPO.nonce
                }, function(response) {
                    if (response.success) {
                        $('#aspo-result').text(response.data + " Оновіть сторінку, щоб побачити прогрес.");
                    } else {
                        $('#aspo-result').text('Помилка: ' + response.data);
                    }
                    $btn.prop('disabled', false).text('Запустити імпорт вручну');
                });
            });
        });
        </script>
        <?php
    }
    
    public function scripts( $hook ) {
        if ( $hook !== 'toplevel_page_aspo' ) return;
        wp_enqueue_script( 'aspo-admin', ASPO_URL . 'admin/aspo-admin.js', [ 'jquery' ], '1.0', true );
        wp_localize_script( 'aspo-admin', 'ASPO', [
            'ajax'  => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'aspo_nonce' ),
        ]);
    }

    // Manual launch
    public function run_import() {
        check_ajax_referer( 'aspo_nonce' );
        $importer = new ASPO_Importer();
        $importer->run('MANUAL'); 
        wp_send_json_success( 'Import executed. Check log.' );
    }

    // Run via Cron
    public function run_cron_import() {
        $importer = new ASPO_Importer();
        $importer->run('CRON');
    }
}