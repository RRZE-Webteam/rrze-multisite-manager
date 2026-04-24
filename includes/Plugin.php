<?php

namespace RRZE\MultisiteManager;

defined('ABSPATH') || exit;

class Plugin {
    protected string $pluginFile;
    protected string $basename = '';
    protected string $directory = '';
    protected string $url = '';
    protected array $data = [];

    public function __construct(string $pluginFile) {
        $this->pluginFile = $pluginFile;
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    public function loaded(): void {
        $this->setBasename()
            ->setDirectory()
            ->setUrl()
            ->setData();
    }

    public function getFile(): string {
        return $this->pluginFile;
    }

    public function getBasename(): string {
        return $this->basename;
    }

    public function setBasename(): self {
        $this->basename = plugin_basename($this->pluginFile);
        return $this;
    }

    public function getDirectory(): string {
        return $this->directory;
    }

    public function setDirectory(): self {
        $this->directory = rtrim(plugin_dir_path($this->pluginFile), '/') . '/';
        return $this;
    }

    public function getPath(string $path = ''): string {
        $path = trim($path, '/');

        if ($path === '') {
            return $this->directory;
        }

        if (pathinfo($path, PATHINFO_EXTENSION) !== '') {
            return $this->directory . $path;
        }

        return $this->directory . $path . '/';
    }

    public function getUrl(string $path = ''): string {
        $path = trim($path, '/');

        if ($path === '') {
            return $this->url;
        }

        if (pathinfo($path, PATHINFO_EXTENSION) !== '') {
            return $this->url . $path;
        }

        return $this->url . $path . '/';
    }

    public function setUrl(): self {
        $this->url = rtrim(plugin_dir_url($this->pluginFile), '/') . '/';
        return $this;
    }

    public function getSlug(): string {
        return sanitize_title(dirname($this->basename));
    }

    public function setData(): self {
        $this->data = get_plugin_data($this->pluginFile, false, false);
        return $this;
    }

    public function getData(): array {
        return $this->data;
    }

    public function getName(): string {
        return (string)($this->data['Name'] ?? '');
    }

    public function getVersion(): string {
        return (string)($this->data['Version'] ?? '');
    }

    public function getRequiresWP(): string {
        return (string)($this->data['RequiresWP'] ?? '');
    }

    public function getRequiresPHP(): string {
        return (string)($this->data['RequiresPHP'] ?? '');
    }

    public function __call(string $name, array $arguments): void {
        if (!method_exists($this, $name)) {
            $message = sprintf('Call to undefined method %1$s::%2$s', __CLASS__, $name);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                throw new \Exception($message);
            }
        }
    }
}
