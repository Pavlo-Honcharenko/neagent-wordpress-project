<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ASPO_Feed {

    private $feed_url = 'https://aspo.biz/api_robots_auto/NeagentOrgUa/NeagentOrgUa.xml';

    public function fetch() {

        $response = wp_remote_get( $this->feed_url, [
            'timeout' => 30,
        ]);

        if ( is_wp_error( $response ) ) {
            return $response->get_error_message();
        }

        $body = wp_remote_retrieve_body( $response );
        if ( empty( $body ) ) {
            return 'Empty feed';
        }

        libxml_use_internal_errors( true );
        $xml = simplexml_load_string( $body );

        if ( ! $xml ) {
            return 'Invalid XML';
        }

        return $xml;
    }
}
