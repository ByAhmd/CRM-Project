<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Enums\CustomFieldEntity;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * The merge tags a template may carry (decision D-10).
 *
 * A tag is `{{ entity.attribute }}`; the entity part is the recipient
 * (`contact.*` with its `account.name`, or `lead.*`), the sender (`user.*`),
 * the organisation or the date. available() lists what a template author may
 * use for a given entity, resolve() produces the values for one recipient
 * and one sender. Values are returned raw — the mail view escapes them.
 */
final class MergeTags
{
    /**
     * @var list<string>
     */
    private const array CONTACT_TAGS = [
        'contact.first_name', 'contact.last_name', 'contact.full_name', 'contact.job_title',
        'contact.email', 'contact.mobile', 'account.name',
    ];

    /**
     * @var list<string>
     */
    private const array LEAD_TAGS = [
        'lead.first_name', 'lead.last_name', 'lead.full_name', 'lead.company_name',
        'lead.job_title', 'lead.email', 'lead.phone',
    ];

    /**
     * @var list<string>
     */
    private const array COMMON_TAGS = ['user.name', 'user.email', 'organisation.name', 'date.today'];

    public function __construct(
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * The entities a template may be scoped to: the two records an email is sent from.
     *
     * @return list<CustomFieldEntity>
     */
    public static function supportedEntities(): array
    {
        return [CustomFieldEntity::Lead, CustomFieldEntity::Contact];
    }

    /** The template entity a recipient record belongs to. */
    public static function entityFor(Model $recipient): CustomFieldEntity
    {
        return match (true) {
            $recipient instanceof Contact => CustomFieldEntity::Contact,
            $recipient instanceof Lead => CustomFieldEntity::Lead,
            default => throw new InvalidArgumentException(__('email.validation.unsupported_recipient')),
        };
    }

    /**
     * Tag => translated label, for the given entity. Entities an email is
     * never sent from only carry the sender, organisation and date tags.
     *
     * @return array<string, string>
     */
    public static function available(CustomFieldEntity $entity): array
    {
        $tags = match ($entity) {
            CustomFieldEntity::Contact => [...self::CONTACT_TAGS, ...self::COMMON_TAGS],
            CustomFieldEntity::Lead => [...self::LEAD_TAGS, ...self::COMMON_TAGS],
            default => self::COMMON_TAGS,
        };

        // Tags contain a dot, so they are looked up as literal keys rather than
        // through the translator's dot-path resolution (as ActivityLogEvent does).
        $translations = __('email.tags');
        $labels = [];

        foreach ($tags as $tag) {
            $labels[$tag] = is_array($translations) ? (string) ($translations[$tag] ?? $tag) : $tag;
        }

        return $labels;
    }

    /**
     * Tag => value for one recipient and one sender; a missing attribute is an empty string.
     *
     * @return array<string, string>
     */
    public function resolve(Model $recipient, User $sender): array
    {
        $values = match (true) {
            $recipient instanceof Contact => [
                'contact.first_name' => $recipient->first_name,
                'contact.last_name' => $recipient->last_name,
                'contact.full_name' => $recipient->full_name,
                'contact.job_title' => $recipient->job_title,
                'contact.email' => $recipient->email,
                'contact.mobile' => $recipient->mobile,
                'account.name' => $recipient->account?->name,
            ],
            $recipient instanceof Lead => [
                'lead.first_name' => $recipient->first_name,
                'lead.last_name' => $recipient->last_name,
                'lead.full_name' => $recipient->full_name,
                'lead.company_name' => $recipient->company_name,
                'lead.job_title' => $recipient->job_title,
                'lead.email' => $recipient->email,
                'lead.phone' => $recipient->phone,
            ],
            default => throw new InvalidArgumentException(__('email.validation.unsupported_recipient')),
        };

        $values += [
            'user.name' => $sender->name,
            'user.email' => $sender->email,
            'organisation.name' => $this->settings->organisationName(),
            'date.today' => Carbon::now($this->settings->timezone())->format('Y-m-d'),
        ];

        return array_map(static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '', $values);
    }
}
