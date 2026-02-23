<?php
namespace FBBot;

use RuntimeException;

class Storage
{
    private $path;
    private $data;
    private $lockHandle;

    public function __construct($filename)
    {
        $this->path = storage_path($filename);
        $this->load();
    }

    private function load()
    {
        if (!file_exists($this->path)) {
            file_put_contents($this->path, json_encode([]));
            chmod($this->path, 0666);
        }
        $content = file_get_contents($this->path);
        $this->data = json_decode($content, true) ?: [];
    }

    public function acquireLock()
    {
        $this->lockHandle = fopen($this->path . '.lock', 'c');
        if (!flock($this->lockHandle, LOCK_EX)) {
            throw new RuntimeException("Could not acquire lock");
        }
    }

    public function releaseLock()
    {
        if ($this->lockHandle) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            @unlink($this->path . '.lock');
            $this->lockHandle = null;
        }
    }

    public function save()
    {
        file_put_contents($this->path, json_encode($this->data, JSON_PRETTY_PRINT));
    }

    public function get($key, $default = null) { return $this->data[$key] ?? $default; }
    public function set($key, $value) { $this->data[$key] = $value; }
    public function delete($key) { unset($this->data[$key]); }
    public function all() { return $this->data; }
    public function __destruct() { $this->releaseLock(); }
}