<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\CloseReasonKind;
use App\Enums\ForecastCategory;
use App\Enums\StageKind;
use App\Models\Deal;
use App\Models\DealCloseReason;
use App\Models\DealProduct;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Deals\DealCloseService;
use App\Services\Deals\DealStageWorkflow;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Carbon;

/**
 * Twenty deals per batch on the default pipeline, plus the stage history of
 * the deals the lead conversions opened (decisions D-6, D-8).
 *
 * A deal is created in the pipeline's default Open stage (DealObserver
 * derives the status), half of them with line items from the demo catalogue
 * — DealProductObserver computes each line and the deal amount — and the rest
 * with a manual amount. Every later move goes through DealStageWorkflow
 * (stage log with the time spent, audit event, notifications) and every win
 * or loss through DealCloseService with a close reason of the right kind; a
 * won deal on a prospect account promotes it to customer. Positions:
 * 0–3 stay in the first stage, 4–7 reach the second, 8–10 the third,
 * 11–15 are won and 16–19 lost.
 */
final class DemoDealsBuilder
{
    public const PER_BATCH = 20;

    public function __construct(
        private readonly DealStageWorkflow $workflow,
        private readonly DealCloseService $close,
        private readonly SettingsRepository $settings,
    ) {}

    public function build(DemoContext $context, int $batch): void
    {
        $pipeline = Pipeline::query()->where('is_default', true)->where('is_active', true)->firstOrFail();
        /** @var list<PipelineStage> $open */
        $open = PipelineStage::query()->where('pipeline_id', $pipeline->getKey())->where('kind', StageKind::Open->value)->orderBy('sort')->orderBy('id')->get()->all();
        $default = PipelineStage::query()->where('pipeline_id', $pipeline->getKey())->where('is_default', true)->firstOrFail();
        $won = DealCloseReason::query()->where('kind', CloseReasonKind::Won->value)->where('is_active', true)->orderBy('sort')->get()->all();
        $lost = DealCloseReason::query()->where('kind', CloseReasonKind::Lost->value)->where('is_active', true)->orderBy('sort')->get()->all();
        $accounts = $context->accountsByBatch[$batch];
        $titles = DemoDataset::dealTitles();

        for ($j = 0; $j < self::PER_BATCH; $j++) {
            $account = $accounts[$j % count($accounts)];
            $owner = $context->userById($account->owner_id);
            $contact = $context->contactsByAccount[(int) $account->getKey()][0] ?? null;
            $arabic = preg_match('/\p{Arabic}/u', (string) $account->name) === 1;
            $created = $context->ago(80 - $j * 3, $batch);
            $closes = $j >= 11;

            $deal = $context->as($owner, $created, fn (): Deal => Deal::query()->create([
                'title' => $titles[$arabic ? 'ar' : 'en'][$j % 5].' - '.$account->name,
                'account_id' => $account->getKey(),
                'contact_id' => $contact?->getKey(),
                'pipeline_id' => $pipeline->getKey(),
                'stage_id' => $default->getKey(),
                'owner_id' => $owner->getKey(),
                'amount' => number_format(9500 + ($j * 4150) % 61000, 2, '.', ''),
                'currency' => $this->settings->currency(),
                'probability' => null,
                'expected_close_date' => ($closes ? $created->copy()->addDays(21) : $context->now->copy()->addDays(15 + $j * 4))->toDateString(),
                'forecast_category' => match (true) {
                    $j >= 8 && $j <= 15 => ForecastCategory::Commit,
                    $j >= 4 && $j <= 7 => ForecastCategory::BestCase,
                    default => ForecastCategory::Pipeline,
                },
                'lead_source_id' => null,
                'lead_id' => null,
                'description' => null,
                'created_by' => $owner->getKey(),
            ]));

            $context->deals[] = $deal;
            $context->dealsByBatch[$batch][] = $deal;
            $context->record('deals', (int) $deal->getKey());

            if ($j % 2 === 0) {
                $this->lineItems($context, $deal, $owner, $j, $created->copy()->addMinutes(10));
            }

            if ($j >= 4 && $j <= 10 || $j >= 11 && $j % 2 === 1) {
                $this->move($context, $deal, $open[1] ?? $default, $owner, $created->copy()->addDays(7), $arabic);
            }

            if ($j >= 8 && $j <= 10) {
                $this->move($context, $deal, $open[2] ?? $default, $owner, $created->copy()->addDays(14), $arabic);
            }

            if ($j >= 11 && $j <= 15) {
                $this->win($context, $deal, $won[$j % count($won)], $owner, $created->copy()->addDays(21), $arabic);
            }

            if ($j >= 16) {
                $this->lose($context, $deal, $lost[$j % count($lost)], $owner, $created->copy()->addDays(21), $arabic);
            }
        }

        $this->convertedDealHistory($context, $batch, $open, $won, $lost);
    }

    /**
     * The deals opened by lead conversions move on from their first stage:
     * one is won early, one reaches the second stage, one is lost, one is in
     * negotiation, one more is won and the last is still new.
     *
     * @param  list<PipelineStage>  $open
     * @param  list<DealCloseReason>  $won
     * @param  list<DealCloseReason>  $lost
     */
    private function convertedDealHistory(DemoContext $context, int $batch, array $open, array $won, array $lost): void
    {
        foreach ($context->convertedDeals as $position => $deal) {
            if (intdiv($position, DemoLeadsBuilder::PER_BATCH) !== $batch) {
                continue;
            }

            $i = $position % DemoLeadsBuilder::PER_BATCH;
            $owner = $context->userById($deal->owner_id);
            $arabic = preg_match('/\p{Arabic}/u', $deal->title) === 1;
            $created = Carbon::instance($deal->created_at ?? $context->now);

            if ($i === 31 || $i === 32 || $i === 35) {
                $this->move($context, $deal, $open[1], $owner, $created->copy()->addDays(2), $arabic);
            }

            if ($i === 31) {
                $this->win($context, $deal, $won[0], $owner, $created->copy()->addDays(5), $arabic);
            } elseif ($i === 33) {
                $this->lose($context, $deal, $lost[1], $owner, $created->copy()->addDays(4), $arabic);
            } elseif ($i === 35) {
                $this->move($context, $deal, $open[2], $owner, $created->copy()->addDays(4), $arabic);
            } elseif ($i === 36) {
                $this->win($context, $deal, $won[1], $owner, $created->copy()->addDays(3), $arabic);
            }

            $context->deals[] = $deal;
            $context->dealsByBatch[$batch][] = $deal;
        }
    }

    private function lineItems(DemoContext $context, Deal $deal, User $owner, int $j, Carbon $moment): void
    {
        $products = $context->products;
        $lines = [
            [$products[intdiv($j, 2) % count($products)], 1 + $j % 3, $j % 4 === 0 ? '5.00' : '0.00'],
        ];

        if ($j % 4 === 0) {
            $lines[] = [$products[(intdiv($j, 2) + 3) % count($products)], 2, '0.00'];
        }

        foreach ($lines as $sort => [$product, $quantity, $discount]) {
            $line = $context->as($owner, $moment, fn (): DealProduct => DealProduct::query()->create([
                'deal_id' => $deal->getKey(),
                'product_id' => $product->getKey(),
                'description' => null,
                'quantity' => number_format($quantity, 2, '.', ''),
                'unit_price' => $product->unit_price,
                'discount_percent' => $discount,
                'sort' => $sort + 1,
            ]));

            $context->record('deal_products', (int) $line->getKey());
        }

        $deal->refresh();
    }

    private function move(DemoContext $context, Deal $deal, PipelineStage $stage, User $actor, Carbon $moment, bool $arabic): void
    {
        $note = $arabic ? 'تمت مراجعة الاحتياج مع العميل.' : 'Requirements reviewed with the customer.';

        $context->as($actor, $moment, fn (): Deal => $this->workflow->transition($deal, $stage, $actor, $note));
    }

    private function win(DemoContext $context, Deal $deal, DealCloseReason $reason, User $actor, Carbon $moment, bool $arabic): void
    {
        $note = $arabic ? 'تم توقيع العقد.' : 'Contract signed.';

        $context->as($actor, $moment, fn (): Deal => $this->close->win($deal, $reason, $actor, $note));
    }

    private function lose(DemoContext $context, Deal $deal, DealCloseReason $reason, User $actor, Carbon $moment, bool $arabic): void
    {
        $notes = $arabic ? 'اختار العميل عرضًا منافسًا بسعر أقل.' : 'The customer chose a lower-priced competitor.';

        $context->as($actor, $moment, fn (): Deal => $this->close->lose($deal, $reason, $actor, $notes));
    }
}
