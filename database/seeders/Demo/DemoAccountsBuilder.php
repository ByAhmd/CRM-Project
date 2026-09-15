<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Industry;
use App\Models\User;
use App\Services\Contacts\ContactService;
use Illuminate\Support\Carbon;

/**
 * Twelve accounts and thirty contacts per batch (step 13 demo data).
 *
 * Written the way the create pages write them: the model is created with
 * its owner and creator (LogsActivity audits `account.created` /
 * `contact.created` as the acting user), and a primary contact goes through
 * ContactService so an account never has two. A customer carries the date it
 * became one; the prospects become customers only by winning a deal (D-6).
 */
final class DemoAccountsBuilder
{
    /** Contacts created per account; the remainder of the thirty have no account. */
    private const CONTACTS_PER_ACCOUNT = [3, 2, 3, 2, 2, 2, 3, 2, 2, 2, 1, 2];

    private const STANDALONE_CONTACTS = 4;

    public function __construct(
        private readonly ContactService $contacts,
    ) {}

    public function build(DemoContext $context, int $batch): void
    {
        $people = DemoDataset::people();
        $cities = DemoDataset::cities();
        $titles = DemoDataset::jobTitles();
        $industries = Industry::query()->pluck('id', 'name_en')->all();
        $person = 0;
        $batchAccounts = [];

        foreach (DemoDataset::accounts() as $index => $definition) {
            $owner = $context->user($definition['owner']);
            $city = $cities[$definition['city']];
            $created = $context->ago(90 - $index * 2.5, $batch);
            $arabic = $definition['arabic'];

            $account = $context->as($owner, $created, fn (): Account => Account::query()->create([
                'name' => self::suffixed($definition['name'], $batch),
                'type' => $definition['type'],
                'industry_id' => $industries[$definition['industry']] ?? null,
                'size' => $definition['size'],
                'website' => 'https://'.$definition['domain'].self::domainSuffix($batch).'.example',
                'email' => 'info@'.$definition['domain'].self::domainSuffix($batch).'.example',
                'phone' => self::phone(11 + $batch * 97 + $index),
                'address_line' => $arabic ? 'طريق الملك فهد، مبنى '.(100 + $index) : 'King Fahd Road, Building '.(100 + $index),
                'city' => $arabic ? $city['ar'] : $city['en'],
                'region' => $arabic ? $city['region_ar'] : $city['region_en'],
                'country' => 'SA',
                'postal_code' => $city['postal'],
                'owner_id' => $owner->getKey(),
                'customer_since' => $definition['type'] === AccountType::Customer ? $created->copy()->subMonths(8 + $index)->toDateString() : null,
                'description' => null,
                'created_by' => $owner->getKey(),
            ]));

            $context->accounts[] = $account;
            $batchAccounts[] = $account;
            $context->record('accounts', (int) $account->getKey());

            for ($n = 0; $n < self::CONTACTS_PER_ACCOUNT[$index]; $n++) {
                $contact = $this->contact($context, $batch, $person++, $arabic, $account, $n === 0, $created->copy()->addDays(1 + $n), $owner, $people, $titles);
                $context->contactsByAccount[(int) $account->getKey()][] = $contact;
            }
        }

        for ($n = 0; $n < self::STANDALONE_CONTACTS; $n++) {
            $owner = $context->user($n % 2 === 0 ? 'sales_rep' : 'sales_rep2');
            $this->contact($context, $batch, $person++, $n % 2 === 0, null, false, $context->ago(55 - $n * 6, $batch), $owner, $people, $titles);
        }

        $context->accountsByBatch[$batch] = $batchAccounts;

        // Summit Real Estate Development is the parent of Najd Logistics Company.
        [$parent, $child] = [$batchAccounts[7], $batchAccounts[1]];
        $context->as($context->user('sales_manager'), $context->ago(58, $batch), fn (): bool => $child->forceFill(['parent_account_id' => $parent->getKey()])->save());
    }

    /**
     * @param  array{ar_first: list<array{string, string}>, ar_last: list<array{string, string}>, en_first: list<array{string, string}>, en_last: list<array{string, string}>}  $people
     * @param  array{ar: list<string>, en: list<string>}  $titles
     */
    private function contact(DemoContext $context, int $batch, int $index, bool $arabic, ?Account $account, bool $primary, Carbon $moment, User $owner, array $people, array $titles): Contact
    {
        [$first, $firstAscii] = $people[$arabic ? 'ar_first' : 'en_first'][$index % 10];
        [$last, $lastAscii] = $people[$arabic ? 'ar_last' : 'en_last'][($index * 3 + 1) % 10];
        $domain = $account === null ? 'personal-mail.example' : (string) parse_url((string) $account->website, PHP_URL_HOST);

        $contact = $context->as($owner, $moment, function () use ($account, $first, $last, $firstAscii, $lastAscii, $domain, $index, $batch, $arabic, $titles, $primary, $owner): Contact {
            $contact = Contact::query()->create([
                'account_id' => $account?->getKey(),
                'first_name' => $first,
                'last_name' => $last,
                'job_title' => $titles[$arabic ? 'ar' : 'en'][$index % 6],
                'department' => null,
                'email' => sprintf('%s.%s%s@%s', $firstAscii, $lastAscii, $batch === 0 ? '' : '.'.($batch + 1), $domain),
                'phone' => null,
                'mobile' => self::phone(500 + $batch * 97 + $index),
                'preferred_locale' => $arabic ? 'ar' : 'en',
                'linkedin_url' => null,
                'is_primary' => $primary,
                'owner_id' => $owner->getKey(),
                'description' => null,
                'created_by' => $owner->getKey(),
            ]);

            $this->contacts->enforcePrimaryRule($contact);

            return $contact;
        });

        $context->contacts[] = $contact;
        $context->record('contacts', (int) $contact->getKey());

        return $contact;
    }

    /** A fictitious Saudi mobile number (05 followed by eight digits). */
    public static function phone(int $seed): string
    {
        return '05'.str_pad((string) (50000000 + ($seed * 7919) % 49999999), 8, '0', STR_PAD_LEFT);
    }

    /** Batches after the first get a visible number so the names stay distinguishable. */
    public static function suffixed(string $name, int $batch): string
    {
        return $batch === 0 ? $name : $name.' '.($batch + 1);
    }

    public static function domainSuffix(int $batch): string
    {
        return $batch === 0 ? '' : '-'.($batch + 1);
    }
}
