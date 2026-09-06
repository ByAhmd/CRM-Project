<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Enums\AccountType;
use App\Enums\ActivityLogEvent;
use App\Enums\ForecastCategory;
use App\Enums\LeadStatusKind;
use App\Exceptions\Leads\InvalidLeadTransitionException;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\LeadStatusLog;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use App\Notifications\LeadConvertedNotification;
use App\Services\Access\RecordVisibilityResolver;
use App\Services\Audit\AuditLogger;
use App\Services\Contacts\ContactService;
use App\Services\Notifications\NotificationRecipients;
use App\Services\Settings\SettingsRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The only way a lead becomes an account, a contact and a deal (decisions
 * D-6, D-7).
 *
 * - only a lead whose status is of kind Qualified converts, and only once;
 * - the account is created from the lead's company, linked to an existing
 *   account within the actor's reach, or skipped for an individual;
 * - the contact is always produced: created from the lead (primary when the
 *   account is new or has no primary contact) or linked to an existing one
 *   within reach, which then remembers the lead;
 * - the deal, when asked for, starts in the chosen pipeline's default stage —
 *   an initial value, not a transition, so no stage log is written;
 * - the lead then takes the Converted status, remembers what it produced and
 *   freezes; the move is logged in lead_status_logs and audited.
 *
 * Everything happens in one transaction with the lead row locked; any
 * refusal or failure rolls the whole conversion back. Once it has committed,
 * the owner is told what the conversion produced when someone else ran it
 * (plan section 3.6).
 */
final class LeadConversionWorkflow
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RecordVisibilityResolver $visibility,
        private readonly ContactService $contacts,
        private readonly SettingsRepository $settings,
        private readonly NotificationRecipients $recipients,
    ) {}

    public function convert(Lead $lead, ConversionRequest $request, User $actor): ConversionResult
    {
        return DB::transaction(function () use ($lead, $request, $actor): ConversionResult {
            /** @var Lead $current */
            $current = Lead::query()->whereKey($lead->getKey())->lockForUpdate()->firstOrFail();
            $from = $current->status;

            if ($current->isConverted()) {
                throw InvalidLeadTransitionException::alreadyConverted();
            }

            if ($from?->kind !== LeadStatusKind::Qualified) {
                throw InvalidLeadTransitionException::notQualified();
            }

            $converted = LeadStatus::query()->where('kind', LeadStatusKind::Converted->value)->first();

            if ($converted === null) {
                throw InvalidLeadTransitionException::convertedStatusMissing();
            }

            $ownerId = (int) ($current->owner_id ?? $actor->getKey());
            $note = trim((string) $request->note);

            $account = $this->resolveAccount($current, $request, $actor, $ownerId);
            $contact = $this->resolveContact($current, $request, $actor, $ownerId, $account);
            $deal = $request->createDeal ? $this->createDeal($current, $request, $actor, $ownerId, $account, $contact) : null;

            $now = now();

            Lead::withoutWorkflowGuard(function () use ($current, $converted, $actor, $account, $contact, $deal, $now): void {
                $current->lead_status_id = $converted->getKey();
                $current->converted_at = $now;
                $current->converted_by = $actor->getKey();
                $current->converted_account_id = $account?->getKey();
                $current->converted_contact_id = $contact->getKey();
                $current->converted_deal_id = $deal?->getKey();
                $current->save();
            });

            LeadStatusLog::query()->create([
                'lead_id' => $current->getKey(),
                'from_status_id' => $from->getKey(),
                'to_status_id' => $converted->getKey(),
                'changed_by' => $actor->getKey(),
                'changed_at' => $now,
                'notes' => $note === '' ? null : $note,
            ]);

            $this->audit->record(ActivityLogEvent::LeadConverted, $current, $actor, [
                'subject_label' => $current->full_name,
                'account_id' => $account?->getKey(),
                'account_name' => $account?->name,
                'contact_id' => $contact->getKey(),
                'contact_name' => $contact->full_name,
                'deal_id' => $deal?->getKey(),
                'deal_title' => $deal?->title,
                'note' => $note === '' ? null : $note,
            ]);

            $lead->setRawAttributes($current->getAttributes(), true);
            $lead->unsetRelation('status');
            $lead->unsetRelation('convertedAccount');
            $lead->unsetRelation('convertedContact');
            $lead->unsetRelation('convertedDeal');

            $this->notifyOwner($current, $account, $contact, $deal, $actor);

            return new ConversionResult(account: $account, contact: $contact, deal: $deal, lead: $lead);
        });
    }

    /**
     * Tells the owner, once the transaction has committed, that someone else
     * converted the lead and what came out of it.
     */
    private function notifyOwner(Lead $lead, ?Account $account, Contact $contact, ?Deal $deal, User $actor): void
    {
        $owner = $this->recipients->ownerOf($lead, $actor);

        if ($owner === null) {
            return;
        }

        $notification = new LeadConvertedNotification($lead, $account?->name, $contact->full_name, $deal?->title, $actor);

        DB::afterCommit(static function () use ($owner, $notification): void {
            $owner->notify($notification->locale($owner->preferredLocale()));
        });
    }

    private function resolveAccount(Lead $lead, ConversionRequest $request, User $actor, int $ownerId): ?Account
    {
        if ($request->wantsExistingAccount()) {
            $account = $request->accountId !== null
                ? $this->visibility->visible($actor, Account::query())->find($request->accountId)
                : null;

            if ($account === null) {
                throw InvalidLeadTransitionException::accountNotAccessible();
            }

            return $account;
        }

        if (! $request->wantsNewAccount()) {
            return null;
        }

        $name = trim((string) ($request->accountName ?? $lead->company_name));

        if ($name === '') {
            throw InvalidLeadTransitionException::accountNameRequired();
        }

        $account = Account::query()->create([
            'name' => $name,
            'type' => AccountType::Prospect,
            'industry_id' => null,
            'email' => $lead->email,
            'phone' => $lead->phone,
            'website' => $lead->website,
            'address_line' => $lead->address_line,
            'city' => $lead->city,
            'region' => $lead->region,
            'country' => $lead->country,
            'postal_code' => $lead->postal_code,
            'owner_id' => $ownerId,
            'created_by' => $actor->getKey(),
        ]);

        $this->copyTags($lead, $account);

        return $account;
    }

    private function resolveContact(Lead $lead, ConversionRequest $request, User $actor, int $ownerId, ?Account $account): Contact
    {
        if ($request->wantsExistingContact()) {
            $contact = $request->contactId !== null
                ? $this->visibility->visible($actor, Contact::query())->find($request->contactId)
                : null;

            if ($contact === null) {
                throw InvalidLeadTransitionException::contactNotAccessible();
            }

            $contact->lead_id = $lead->getKey();
            $becomesPrimary = false;

            if ($contact->account_id === null && $account !== null) {
                $contact->account_id = $account->getKey();
                $becomesPrimary = $this->accountNeedsPrimaryContact($account);
            }

            $contact->save();

            if ($becomesPrimary) {
                $this->contacts->markPrimary($contact);
            }

            return $contact;
        }

        $isPrimary = $this->accountNeedsPrimaryContact($account);

        $contact = Contact::query()->create([
            'account_id' => $account?->getKey(),
            'lead_id' => $lead->getKey(),
            'first_name' => $lead->first_name,
            'last_name' => $lead->last_name,
            'job_title' => $lead->job_title,
            'email' => $lead->email,
            'mobile' => $lead->phone,
            'preferred_locale' => (string) config('app.locale'),
            'address_line' => $account === null ? $lead->address_line : null,
            'city' => $account === null ? $lead->city : null,
            'region' => $account === null ? $lead->region : null,
            'country' => $account === null ? $lead->country : null,
            'postal_code' => $account === null ? $lead->postal_code : null,
            'is_primary' => $isPrimary,
            'owner_id' => $ownerId,
            'created_by' => $actor->getKey(),
        ]);

        $this->contacts->enforcePrimaryRule($contact);
        $this->copyTags($lead, $contact);

        return $contact;
    }

    /** A freshly created account, or one without a primary contact, takes the contact as primary (D-6). */
    private function accountNeedsPrimaryContact(?Account $account): bool
    {
        return $account !== null
            && ($account->wasRecentlyCreated || ! $account->contacts()->where('is_primary', true)->exists());
    }

    private function createDeal(Lead $lead, ConversionRequest $request, User $actor, int $ownerId, ?Account $account, Contact $contact): Deal
    {
        // A new deal only ever starts in an active pipeline, whoever calls the workflow.
        $pipeline = Pipeline::query()
            ->where('is_active', true)
            ->when(
                $request->pipelineId !== null,
                fn (Builder $query): Builder => $query->whereKey($request->pipelineId),
                fn (Builder $query): Builder => $query->where('is_default', true),
            )
            ->first();

        if ($pipeline === null) {
            throw InvalidLeadTransitionException::pipelineInactive();
        }

        $stage = PipelineStage::query()->where('pipeline_id', $pipeline->getKey())->where('is_default', true)->first();

        if ($stage === null) {
            throw InvalidLeadTransitionException::pipelineHasNoDefaultStage();
        }

        $title = trim((string) ($request->dealTitle ?? ''));

        if ($title === '') {
            $title = $account !== null ? $account->name : $lead->full_name;
        }

        return Deal::query()->create([
            'title' => $title,
            'account_id' => $account?->getKey(),
            'contact_id' => $contact->getKey(),
            'pipeline_id' => $pipeline->getKey(),
            'stage_id' => $stage->getKey(),
            'owner_id' => $ownerId,
            'amount' => $request->dealAmount ?? '0',
            'currency' => $this->settings->currency(),
            'forecast_category' => ForecastCategory::Pipeline,
            'expected_close_date' => $request->expectedCloseDate,
            'lead_source_id' => $lead->lead_source_id,
            'lead_id' => $lead->getKey(),
            'created_by' => $actor->getKey(),
        ]);
    }

    private function copyTags(Lead $lead, Account|Contact $target): void
    {
        $tagIds = $lead->tags()->pluck('tags.id')->all();

        if ($tagIds !== []) {
            $target->tags()->sync($tagIds);
        }
    }
}
