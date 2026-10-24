<?php

declare( strict_types=1 );

namespace Switchyard\Pro;

final class Updater {

	private const REPO      = 'jgalea/switchyard-crm-pro';
	private const SLUG      = 'switchyard-crm-pro';
	private const TRANSIENT = 'switchyard_pro_release';

	public function register_hooks(): void {
		add_filter( 'update_plugins_github.com', array( $this, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( $this, 'info' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ), 10, 2 );
	}

	public function check( mixed $update, mixed $plugin_data, mixed $plugin_file ): mixed {
		if ( plugin_basename( SWITCHYARD_PRO_FILE ) !== $plugin_file ) {
			return $update;
		}

		$release = $this->latest_release();

		if ( null === $release ) {
			return $update;
		}

		return array(
			'slug'    => self::SLUG,
			'version' => $release['version'],
			'url'     => $release['url'],
			'package' => $release['package'],
		);
	}

	public function info( mixed $result, mixed $action, mixed $args ): mixed {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || self::SLUG !== ( $args->slug ?? '' ) ) {
			return $result;
		}

		$release = $this->latest_release();

		if ( null === $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'Switchyard CRM Pro',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => '<a href="https://jeangalea.com/">Jean Galea</a>',
			'homepage'      => 'https://github.com/' . self::REPO,
			'download_link' => $release['package'],
			'requires'      => '6.4',
			'requires_php'  => '8.1',
			'sections'      => array( 'changelog' => wp_kses_post( wpautop( $release['notes'] ) ) ),
		);
	}

	public function clear_cache( mixed $upgrader, mixed $options ): void {
		if ( is_array( $options ) && 'update' === ( $options['action'] ?? '' ) && 'plugin' === ( $options['type'] ?? '' ) ) {
			delete_site_transient( self::TRANSIENT );
		}
	}

	/**
	 * @return array{version: string, package: string, url: string, notes: string}|null
	 */
	private function latest_release(): ?array {
		$cached = get_site_transient( self::TRANSIENT );

		if ( false !== $cached ) {
			return is_array( $cached ) && ! empty( $cached ) ? $cached : null;
		}

		$release  = array();
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/vnd.github+json' ),
			)
		);

		if ( 200 === wp_remote_retrieve_response_code( $response ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );

			foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
				if ( self::SLUG . '.zip' === ( $asset['name'] ?? '' ) ) {
					$release = array(
						'version' => ltrim( (string) ( $data['tag_name'] ?? '' ), 'v' ),
						'package' => (string) $asset['browser_download_url'],
						'url'     => (string) ( $data['html_url'] ?? '' ),
						'notes'   => (string) ( $data['body'] ?? '' ),
					);
					break;
				}
			}
		}

		set_site_transient( self::TRANSIENT, $release, $release ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );

		return $release ? $release : null;
	}
}
