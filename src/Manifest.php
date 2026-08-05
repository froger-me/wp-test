<?php

declare(strict_types=1);

namespace WpTest;

use RuntimeException;

final class Manifest
{
	/** @var array<string, mixed> */
	private array $data;

	/** @param array<string, mixed> $data */
	private function __construct(array $data)
	{
		$this->data = $data;
	}

	public static function fromFile(string $path): self
	{
		if (! is_file($path)) {
			throw new RuntimeException(sprintf('Test manifest does not exist: %s', $path));
		}
		$data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		if (! is_array($data)) {
			throw new RuntimeException('The test manifest must contain a JSON object.');
		}
		return new self($data);
	}

	public function profile(): string
	{
		return (string) ($this->data['profile'] ?? 'default');
	}

	public function isMultisite(): bool
	{
		return (bool) ($this->data['multisite'] ?? false);
	}

	/** @return list<array<string, mixed>> */
	public function plugins(): array
	{
		$plugins = $this->data['plugins'] ?? [];
		return is_array($plugins) ? array_values($plugins) : [];
	}

	/** @return list<array<string, mixed>> */
	public function themes(): array
	{
		$themes = $this->data['themes'] ?? [];
		return is_array($themes) ? array_values($themes) : [];
	}

	/** @return list<string> */
	public function pluginFiles(): array
	{
		return array_values(array_map(static fn (array $plugin): string => (string) $plugin['file'], $this->plugins()));
	}

	public function stylesheet(): string
	{
		return (string) ($this->data['stylesheet'] ?? '');
	}

	public function template(): string
	{
		return (string) ($this->data['template'] ?? '');
	}

	public function siteBootstrap(): ?string
	{
		$value = $this->data['site_bootstrap'] ?? null;
		return is_string($value) && $value !== '' ? $value : null;
	}

	/** @return list<array{type:string,slug:string,path:string}> */
	public function extensionBootstraps(): array
	{
		$bootstraps = [];
		foreach (array_merge($this->plugins(), $this->themes()) as $extension) {
			if (empty($extension['tests_enabled'])) {
				continue;
			}
			$path = $extension['bootstrap'] ?? null;
			if (! is_string($path) || $path === '') {
				continue;
			}
			$bootstraps[] = [
				'type' => (string) ($extension['type'] ?? 'extension'),
				'slug' => (string) ($extension['slug'] ?? 'unknown'),
				'path' => $path,
			];
		}
		return $bootstraps;
	}

	/** @return array<string, mixed> */
	public function toArray(): array
	{
		return $this->data;
	}
}
