<?php
namespace Kanboard\Plugin\Steward\Controller;

use DateTimeImmutable;
use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;

/** Settings → Steward → Audit log. Admin only and read-only: index is the only action, so nothing can delete rows. */
class StewardAuditController extends BaseController
{
    public const PER_PAGE = 50;

    public function index(): void
    {
        if (! $this->userSession->isAdmin()) {
            throw new AccessForbiddenException();
        }

        $form = [
            'event' => $this->param('event'),
            'user_id' => ctype_digit($this->param('user_id')) ? $this->param('user_id') : '',
            'from' => $this->date('from') !== null ? $this->param('from') : '',
            'to' => $this->date('to') !== null ? $this->param('to') : '',
        ];
        $filters = [
            'event' => $form['event'],
            'user_id' => $form['user_id'],
            'from' => $form['from'] !== '' ? $this->date('from')->getTimestamp() : '',
            'to' => $form['to'] !== '' ? $this->date('to')->modify('+1 day')->getTimestamp() - 1 : '',
        ];

        $audit = $this->container['stewardAudit'];
        $paginator = $this->paginator
            ->setUrl('StewardAuditController', 'index', ['plugin' => 'Steward'] + array_filter($form, fn ($v) => $v !== ''))
            ->setMax(self::PER_PAGE)
            ->calculate();
        $paginator
            ->setTotal($audit->count($filters))
            ->setCollection($audit->find($filters, self::PER_PAGE, ($paginator->getPage() - 1) * self::PER_PAGE));

        $users = [];
        foreach ($this->userModel->getAll() as $user) {
            $users[(int) $user['id']] = (string) $user['username'];
        }
        asort($users, SORT_NATURAL | SORT_FLAG_CASE);

        $this->response->html($this->helper->layout->config('Steward:audit/index', [
            'title' => t('Settings').' &gt; Steward &gt; '.t('Audit log'),
            'paginator' => $paginator,
            'form' => $form,
            'events' => $audit->events(),
            'users' => $users,
        ]));
    }

    /** Query-string value as a string; arrays and other shapes become ''. */
    private function param(string $name): string
    {
        $value = $this->request->getStringParam($name);
        return is_string($value) ? trim($value) : '';
    }

    /** Start of the given Y-m-d day in the server timezone, or null when the value is not exactly such a date. */
    private function date(string $name): ?DateTimeImmutable
    {
        $value = $this->param($name);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }
}
