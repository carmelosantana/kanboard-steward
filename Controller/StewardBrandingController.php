<?php
namespace Kanboard\Plugin\Steward\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\Steward\Model\Branding;
use Kanboard\Plugin\Steward\Model\FeatureGate;
use Kanboard\Plugin\Steward\Model\ImageProcessor;
use RuntimeException;
use Throwable;

/** Settings → Steward → Branding (admin), and the public image endpoint (spec §3). */
class StewardBrandingController extends BaseController
{
    private const YEAR = 31536000;

    public function show(): void
    {
        if (! $this->featureOn()) {
            return;
        }
        $this->assertAdmin();
        $branding = $this->branding();

        $this->response->html($this->helper->layout->config('Steward:config/branding', [
            'title' => t('Settings').' &gt; Steward &gt; '.t('Branding'),
            'values' => ['steward_site_name' => $branding->siteName()],
            'logo_url' => $branding->imageUrl('logo'),
            'favicon_url' => $branding->imageUrl('favicon180'),
            'max_length' => Branding::SITE_NAME_MAX,
            'shadcn_import' => $branding->shadcnImports() !== [],
        ]));
    }

    /**
     * Import from ShadcnTheme (spec §5.6): its logo/favicon go through the same pipeline into Steward's empty
     * slots. All or nothing: an SVG or any image the pipeline refuses writes nothing and flashes why.
     */
    public function import(): void
    {
        if (! $this->featureOn()) {
            return;
        }
        $this->assertAdmin();
        if (! $this->request->isPost()) {
            throw new AccessForbiddenException();
        }
        $this->checkCSRFForm();

        $branding = $this->branding();
        $sources = $branding->shadcnImports();
        if ($sources === []) {
            $this->flash->failure(t('Nothing to import from ShadcnTheme'));
            $this->redirectToShow();
            return;
        }

        $results = [];
        foreach ($sources as $slot => $path) {
            $result = $this->processShadcnImage($slot, $path);
            if (! $result['ok']) {
                $this->flash->failure($result['error']);
                $this->redirectToShow();
                return;
            }
            $results[$slot] = $result;
        }

        $actorId = (int) $this->userSession->getId();
        try {
            foreach ($results as $slot => $result) {
                $branding->save($slot, $result['blobs'], $result['hash']);
                $this->container['stewardAudit']->logInternal('branding.changed', $actorId, null, ['slot' => $slot, 'action' => 'import']);
            }
        } catch (RuntimeException $e) {
            $this->flash->failure(t('Unable to save your settings.'));
            $this->redirectToShow();
            return;
        }

        $this->flash->success(t('Imported from ShadcnTheme.'));
        $this->redirectToShow();
    }

    /** @return array{ok:bool,error?:string,blobs?:array<string,string>,hash?:string} error already translated */
    private function processShadcnImage(string $slot, string $path): array
    {
        try {
            $blob = $this->objectStorage->get($path);
        } catch (Throwable $e) {
            $this->logger->error('Steward branding: ShadcnTheme '.$slot.' '.$path.' unreadable: '.$e->getMessage());
            $blob = null;
        }
        if (! is_string($blob) || $blob === '') {
            return ['ok' => false, 'error' => $slot === 'logo'
                ? t('Could not read your ShadcnTheme logo')
                : t('Could not read your ShadcnTheme favicon')];
        }

        // By content, not by the stored file name: GD cannot read SVG, so the admin uploads a PNG instead.
        $rasterMagic = preg_match('/^(\x89PNG|\xFF\xD8|GIF8|RIFF)/', $blob) === 1;
        if (! $rasterMagic && preg_match('/<svg[\s>]/i', substr($blob, 0, 4096)) === 1) {
            return ['ok' => false, 'error' => $slot === 'logo'
                ? t('Your ShadcnTheme logo is an SVG; upload a PNG instead')
                : t('Your ShadcnTheme favicon is an SVG; upload a PNG instead')];
        }

        $tmp = tempnam(sys_get_temp_dir(), 'steward-import-');
        if ($tmp === false || file_put_contents($tmp, $blob) === false) {
            return ['ok' => false, 'error' => t(ImageProcessor::ERROR_UNREADABLE)];
        }
        try {
            $result = (new ImageProcessor())->process($tmp, strlen($blob), $this->branding()->targets($slot));
        } finally {
            unlink($tmp);
        }
        return $result['ok'] ? $result : ['ok' => false, 'error' => t($result['error'])];
    }

    /**
     * One form: site name, an optional upload per slot, a remove box per slot. Everything is checked and
     * processed before anything is written; any failure writes nothing and flashes the reason.
     */
    public function save(): void
    {
        if (! $this->featureOn()) {
            return;
        }
        $this->assertAdmin();
        if (! $this->request->isPost()) {
            throw new AccessForbiddenException();
        }
        // getValues() validates and consumes the single-use CSRF token: call it once.
        $values = $this->request->getValues();
        if ($values === []) {
            throw new AccessForbiddenException();
        }

        $error = null;
        $siteName = Branding::cleanSiteName(is_string($values['steward_site_name'] ?? null) ? $values['steward_site_name'] : '');
        if ($siteName === null) {
            $error = t('Site name is too long (max %d characters)', Branding::SITE_NAME_MAX);
        }

        $uploads = [];
        foreach (array_keys(Branding::SLOTS) as $slot) {
            if ($error !== null) {
                break;
            }
            $file = $this->request->getFileInfo($slot);
            $code = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($code === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($code !== UPLOAD_ERR_OK || ! is_string($file['tmp_name'] ?? null)) {
                $error = $code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE
                    ? t(ImageProcessor::ERROR_FILE_TOO_LARGE)
                    : t('Upload failed');
                break;
            }
            $result = (new ImageProcessor())->process($file['tmp_name'], (int) ($file['size'] ?? 0), $this->branding()->targets($slot));
            if (! $result['ok']) {
                $error = t($result['error']);
                break;
            }
            $uploads[$slot] = $result;
        }

        if ($error !== null) {
            $this->flash->failure($error);
            $this->redirectToShow();
            return;
        }

        $actorId = (int) $this->userSession->getId();
        $audit = $this->container['stewardAudit'];
        $branding = $this->branding();

        try {
            foreach (array_keys(Branding::SLOTS) as $slot) {
                if (isset($uploads[$slot])) {
                    $branding->save($slot, $uploads[$slot]['blobs'], $uploads[$slot]['hash']);
                    $audit->logInternal('branding.changed', $actorId, null, ['slot' => $slot, 'action' => 'upload']);
                } elseif (! empty($values['remove_'.$slot]) && $branding->remove($slot)) {
                    $audit->logInternal('branding.changed', $actorId, null, ['slot' => $slot, 'action' => 'remove']);
                }
            }

            if ($siteName !== $branding->siteName()) {
                if (! $this->configModel->save(['steward_site_name' => $siteName])) {
                    throw new RuntimeException('config save failed');
                }
                $audit->logInternal('settings.changed', $actorId, null, ['keys' => ['steward_site_name']]);
            }
        } catch (RuntimeException $e) {
            $this->flash->failure(t('Unable to save your settings.'));
            $this->redirectToShow();
            return;
        }

        $this->flash->success(t('Settings saved successfully.'));
        $this->redirectToShow();
    }

    /** Public (the login page shows the logo). `slot` from Branding::IMAGES only; anything else is the fixed 404. */
    public function image(): void
    {
        if (! $this->featureOn()) {
            return;
        }
        $slot = $this->request->getStringParam('slot');
        $image = is_string($slot) && isset(Branding::IMAGES[$slot]) ? $this->branding()->image($slot) : null;
        if ($image === null) {
            $this->notFound();
            return;
        }

        $hash = $image['hash'];
        $this->response
            ->withHeader('ETag', '"'.$hash.'"')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox");

        // Long cache only for the URL that names this content; any other URL revalidates by ETag.
        if ($this->request->getStringParam('h') === $hash) {
            // Pragma and Expires replace the session cache limiter's no-cache pair.
            $this->response
                ->withHeader('Cache-Control', 'public, max-age='.self::YEAR.', immutable')
                ->withHeader('Pragma', 'cache')
                ->withHeader('Expires', gmdate('D, d M Y H:i:s', time() + self::YEAR).' GMT');
        } else {
            $this->response->withHeader('Cache-Control', 'no-cache');
        }

        if ($this->matchesIfNoneMatch($hash)) {
            $this->response->withStatusCode(304)->send();
            return;
        }

        $this->response->withContentType('image/png')->withBody($image['blob'])->send();
    }

    private function matchesIfNoneMatch(string $hash): bool
    {
        $header = trim((string) $this->request->getHeader('If-None-Match'));
        if ($header === '') {
            return false;
        }
        if ($header === '*') {
            return true;
        }
        foreach (explode(',', $header) as $tag) {
            $tag = trim($tag);
            if (str_starts_with($tag, 'W/')) {
                $tag = substr($tag, 2);
            }
            if (trim($tag, '"') === $hash) {
                return true;
            }
        }
        return false;
    }

    /**
     * Repeats FeatureGate's check (spec §1, defence in depth): with branding off every action answers the
     * gate's fixed 404, before ACL-dependent work, so a bypassed gate still serves nothing.
     */
    private function featureOn(): bool
    {
        if ($this->container['stewardFeatures']->isEnabled('branding')) {
            return true;
        }
        $this->notFound();
        return false;
    }

    private function notFound(): void
    {
        $this->response->html(FeatureGate::NOT_FOUND_BODY, 404);
    }

    private function branding(): Branding
    {
        return $this->container['stewardBranding'];
    }

    private function redirectToShow(): void
    {
        $this->response->redirect($this->helper->url->to('StewardBrandingController', 'show', ['plugin' => 'Steward']));
    }

    private function assertAdmin(): void
    {
        if (! $this->userSession->isAdmin()) {
            throw new AccessForbiddenException();
        }
    }
}
