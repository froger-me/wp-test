<?php
/**
 * Runtime test manifest value object.
 *
 * @package WpTest
 */

declare(strict_types=1);

namespace WpTest;

use RuntimeException;

/** Provides typed access to a generated runtime manifest. */
final class Manifest {

	/**
	 * Manifest data.
	 *
	 * @var array<string, mixed>
	 */
	private array $data;

	/**
	 * Create a manifest.
	 *
	 * @param array<string, mixed> $data Manifest data.
	 */
	private function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * Load a manifest from a JSON file.
	 *
	 * @param string $path Manifest path.
	 * @return self
	 * @throws RuntimeException When the manifest is missing or invalid.
	 */
	public static function from_file( string $path ): self {
		if ( ! is_file( $path ) ) {
			throw new RuntimeException( sprintf( 'Test manifest does not exist: %s', $path ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve the exact local path.
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read a local manifest before WordPress loads.
		$data = json_decode( (string) file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
		if ( ! is_array( $data ) ) {
			throw new RuntimeException( 'The test manifest must contain a JSON object.' );
		}
		return new self( $data );
	}

	/** Return the selected test profile. */
	public function profile(): string {
		return (string) ( $this->data['profile'] ?? 'default' );
	}

	/** Return whether multisite mode is enabled. */
	public function is_multisite(): bool {
		return (bool) ( $this->data['multisite'] ?? false );
	}

	/**
	 * Return plugin manifest entries.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function plugins(): array {
		$plugins = $this->data['plugins'] ?? array();
		return is_array( $plugins ) ? array_values( $plugins ) : array();
	}

	/**
	 * Return theme manifest entries.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function themes(): array {
		$themes = $this->data['themes'] ?? array();
		return is_array( $themes ) ? array_values( $themes ) : array();
	}

	/**
	 * Return active plugin basenames.
	 *
	 * @return list<string>
	 */
	public function plugin_files(): array {
		return array_values( array_map( static fn ( array $plugin ): string => (string) $plugin['file'], $this->plugins() ) );
	}

	/** Return the active stylesheet slug. */
	public function stylesheet(): string {
		return (string) ( $this->data['stylesheet'] ?? '' );
	}

	/** Return the active template slug. */
	public function template(): string {
		return (string) ( $this->data['template'] ?? '' );
	}

	/** Return the optional site bootstrap path. */
	public function site_bootstrap(): ?string {
		$value = $this->data['site_bootstrap'] ?? null;
		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * Return enabled extension bootstrap entries.
	 *
	 * @return list<array{type:string,slug:string,path:string}>
	 */
	public function extension_bootstraps(): array {
		$bootstraps = array();
		foreach ( array_merge( $this->plugins(), $this->themes() ) as $extension ) {
			if ( empty( $extension['tests_enabled'] ) ) {
				continue;
			}
			$path = $extension['bootstrap'] ?? null;
			if ( ! is_string( $path ) || '' === $path ) {
				continue;
			}
			$bootstraps[] = array(
				'type' => (string) ( $extension['type'] ?? 'extension' ),
				'slug' => (string) ( $extension['slug'] ?? 'unknown' ),
				'path' => $path,
			);
		}
		return $bootstraps;
	}

	/**
	 * Return the raw manifest data.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return $this->data;
	}
}
