<?php

namespace App\Providers;

use App\Models\Campaign;
use App\Support\AccountCampaignCatalog;
use App\Support\AccountRoleCatalog;
use App\Support\WorkflowRemarks;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(WorkflowRemarks::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // TLS may terminate at the tunnel while PHP receives plain HTTP.
        // Keep forms, assets, and redirects on the configured public scheme.
        if (parse_url((string) config('app.url'), PHP_URL_SCHEME) === 'https') {
            URL::forceScheme('https');
        }

        Paginator::useBootstrapFive();
        View::composer('admin.accounts.partials.create-modal', fn ($view) => $view->with([
            'newUserRoles' => AccountRoleCatalog::options(),
            'newUserCampaigns' => Campaign::query()->where('is_active', true)
                ->whereIn('name', AccountCampaignCatalog::NAMES)->orderBy('name')->get(['id', 'name']),
        ]));
        View::composer([
            'dashboard', 'dashboard.team-leader', 'purchase-requests.index', 'purchase-requests.show',
            'purchase-orders.workflow-tracker', 'purchase-orders.workflow-tracker-show',
            'president-approvals.index', 'budget-requests.index', 'budget-requests.finalize',
            'budget-requests.document', 'budget-requests.partials.transaction-sheet', 'budget-requests.partials.br-document-sheet',
        ], fn ($view) => app(WorkflowRemarks::class)->prepare($view));
    }
}
