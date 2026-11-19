<?php
namespace Kanboard\Plugin\Steward\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Plugin\Steward\Model\FeatureGate;
use Kanboard\Plugin\Steward\Model\ProjectPageSettings;
use Kanboard\Plugin\Steward\Model\PublicPage;

/**
 * Public project page (spec §5.3), anonymous, at /public/project/:token. Shown only while the feature, core's
 * public access and the project's Public page are all on; anything else gets FeatureGate's 404, so a visitor
 * can't tell which one is missing. The token is the only key and never appears in the page. Never indexed: a
 * listed project's indexable URL is /explore/:identifier.
 */
class StewardPublicController extends BaseController
{
    public function show(): void
    {
        // Every header on every answer: the URL holds the token.
        $this->response->withHeader('Referrer-Policy', 'no-referrer');

        $project = $this->publicProject($this->request->getStringParam('token'));
        $settings = $project !== null ? (new ProjectPageSettings($this->container))->get((int) $project['id']) : null;
        if ($settings === null || ! $settings['public']) {
            $this->response->html(FeatureGate::NOT_FOUND_BODY, 404);
            return;
        }
        $this->response->withHeader('X-Robots-Tag', 'noindex');
        $this->response->html((new PublicPage($this->container))->project($project, $settings, false));
    }

    /** The project for $token while the feature and core public access are on (repeats FeatureGate's check). */
    private function publicProject(string $token): ?array
    {
        if (! $this->container['stewardFeatures']->isEnabled('public_pages') || $token === '') {
            return null;
        }
        $project = $this->projectModel->getByToken($token);
        if (empty($project) || empty($project['is_public']) || ! hash_equals((string) $project['token'], $token)) {
            return null;
        }
        return $project;
    }
}
