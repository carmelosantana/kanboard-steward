<?php
namespace Kanboard\Plugin\Steward\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Plugin\Steward\Model\FeatureGate;
use Kanboard\Plugin\Steward\Model\PublicPage;

/**
 * Project directory at /explore and listed pages at /explore/:identifier (spec §5.4), anonymous and indexable.
 * The directory needs the explore feature; a listed page needs explore and public pages, and the project must
 * pass every PublicPage::listed() check. Any failure answers FeatureGate's 404, the same as the token page.
 */
class StewardExploreController extends BaseController
{
    public function index(): void
    {
        $this->response->withHeader('Referrer-Policy', 'no-referrer');
        if (! $this->container['stewardFeatures']->isEnabled('explore')) {
            $this->response->html(FeatureGate::NOT_FOUND_BODY, 404);
            return;
        }
        $this->response->html((new PublicPage($this->container))->directory());
    }

    public function show(): void
    {
        $this->response->withHeader('Referrer-Policy', 'no-referrer');
        $features = $this->container['stewardFeatures'];
        $page = new PublicPage($this->container);
        $entry = $features->isEnabled('explore') && $features->isEnabled('public_pages')
            ? $page->findListed($this->request->getStringParam('identifier'))
            : null;
        if ($entry === null) {
            $this->response->html(FeatureGate::NOT_FOUND_BODY, 404);
            return;
        }
        $this->response->html($page->project($entry['project'], $entry['settings'], true));
    }
}
