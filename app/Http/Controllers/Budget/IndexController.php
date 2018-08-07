<?php
/**
 * IndexController.php
 * Copyright (c) 2018 thegrumpydictator@gmail.com
 *
 * This file is part of Firefly III.
 *
 * Firefly III is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Firefly III is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Firefly III. If not, see <http://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace FireflyIII\Http\Controllers\Budget;


use Carbon\Carbon;
use Exception;
use FireflyIII\Http\Controllers\Controller;
use FireflyIII\Repositories\Budget\BudgetRepositoryInterface;
use FireflyIII\Support\Http\Controllers\DateCalculation;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Log;

/**
 *
 * Class IndexController
 */
class IndexController extends Controller
{

    use DateCalculation;
    /** @var BudgetRepositoryInterface The budget repository */
    private $repository;

    /**
     * IndexController constructor.
     */
    public function __construct()
    {
        parent::__construct();

        app('view')->share('hideBudgets', true);

        $this->middleware(
            function ($request, $next) {
                app('view')->share('title', (string)trans('firefly.budgets'));
                app('view')->share('mainTitleIcon', 'fa-tasks');
                $this->repository = app(BudgetRepositoryInterface::class);
                $this->repository->cleanupBudgets();

                return $next($request);
            }
        );
    }


    /**
     * Show all budgets.
     *
     * @param Request     $request
     * @param string|null $moment
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function index(Request $request, string $moment = null)
    {
        // collect some basic vars:
        $range           = app('preferences')->get('viewRange', '1M')->data;
        $start           = session('start', new Carbon);
        $end             = session('end', new Carbon);
        $page            = 0 === (int)$request->get('page') ? 1 : (int)$request->get('page');
        $pageSize        = (int)app('preferences')->get('listPageSize', 50)->data;
        $moment          = $moment ?? '';
        $defaultCurrency = app('amount')->getDefaultCurrency();

        // make a date if the data is given.
        if ('' !== (string)$moment) {
            try {
                $start = new Carbon($moment);
                /** @var Carbon $end */
                $end = app('navigation')->endOfPeriod($start, $range);
            } catch (Exception $e) {
                // start and end are already defined.
                Log::debug(sprintf('start and end are already defined: %s', $e->getMessage()));
            }
        }

        // make the next and previous period, and calculate the periods used for period navigation
        $next = clone $end;
        $next->addDay();
        $prev = clone $start;
        $prev->subDay();
        $prev         = app('navigation')->startOfPeriod($prev, $range);
        $previousLoop = $this->getPreviousPeriods($start, $range);
        $nextLoop     = $this->getNextPeriods($end, $range);
        $currentMonth = app('navigation')->periodShow($start, $range);
        $nextText     = app('navigation')->periodShow($next, $range);
        $prevText     = app('navigation')->periodShow($prev, $range);

        // number of days for consistent budgeting.
        $activeDaysPassed = $this->activeDaysPassed($start, $end); // see method description.
        $activeDaysLeft   = $this->activeDaysLeft($start, $end); // see method description.

        // get all budgets, and paginate them into $budgets.
        $collection = $this->repository->getActiveBudgets();
        $total      = $collection->count();
        $budgets    = $collection->slice(($page - 1) * $pageSize, $pageSize);

        // get all inactive budgets, and simply list them:
        $inactive = $this->repository->getInactiveBudgets();

        // collect budget info to fill bars and so on.
        $budgetInformation = $this->repository->collectBudgetInformation($collection, $start, $end);

        // to display available budget:
        $available = $this->repository->getAvailableBudget($defaultCurrency, $start, $end);
        $spent     = array_sum(array_column($budgetInformation, 'spent'));
        $budgeted  = array_sum(array_column($budgetInformation, 'budgeted'));


        // paginate budgets
        $paginator = new LengthAwarePaginator($budgets, $total, $pageSize, $page);
        $paginator->setPath(route('budgets.index'));

        return view(
            'budgets.index', compact(
                               'available', 'currentMonth', 'next', 'nextText', 'prev', 'paginator',
                               'prevText',
                               'page', 'activeDaysPassed', 'activeDaysLeft',
                               'budgetInformation',
                               'inactive', 'budgets', 'spent', 'budgeted', 'previousLoop', 'nextLoop', 'start', 'end'
                           )
        );
    }


}