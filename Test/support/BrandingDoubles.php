<?php
// Test doubles shared by the branding tests.

use Kanboard\Core\Http\Response;
use Kanboard\Core\ObjectStorage\ObjectStorageException;
use Kanboard\Core\ObjectStorage\ObjectStorageInterface;

/** In-memory ObjectStorage that can be told to fail. */
class StewardMemoryStorage implements ObjectStorageInterface
{
    public array $objects = [];
    public bool $failPut = false;
    public bool $failRemove = false;

    public function get($key)
    {
        if (! isset($this->objects[$key])) {
            throw new ObjectStorageException('missing '.$key);
        }
        return $this->objects[$key];
    }

    public function put($key, &$blob)
    {
        if ($this->failPut) {
            throw new ObjectStorageException('disk full');
        }
        $this->objects[$key] = $blob;
    }

    public function output($key)
    {
        echo $this->get($key);
    }

    public function moveFile($filename, $key)
    {
        throw new ObjectStorageException('not used');
    }

    public function moveUploadedFile($filename, $key)
    {
        throw new ObjectStorageException('not used');
    }

    public function remove($key)
    {
        if ($this->failRemove) {
            throw new ObjectStorageException('cannot remove');
        }
        unset($this->objects[$key]);
        return true;
    }
}

/** Records what would be sent instead of calling header(). */
class StewardBrandingResponse extends Response
{
    public bool $sent = false;

    public function send()
    {
        $this->sent = true;
    }

    private function prop(string $name): mixed
    {
        $p = new ReflectionProperty(Response::class, $name);
        return $p->getValue($this);
    }

    public function headers(): array { return $this->prop('httpHeaders'); }
    public function code(): int { return $this->prop('httpStatusCode'); }
    public function body(): string { return (string) $this->prop('httpBody'); }
}

class StewardBrandingBodyOnlyLayoutHelper extends \Kanboard\Helper\LayoutHelper
{
    public function config($template, array $params)
    {
        return $this->template->render($template, $params);
    }
}
