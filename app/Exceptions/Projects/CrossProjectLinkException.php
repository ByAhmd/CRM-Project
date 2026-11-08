<?php

declare(strict_types=1);

namespace App\Exceptions\Projects;

use DomainException;
use Illuminate\Database\Eloquent\Model;

/**
 * Two records of different projects were about to be tied together
 * (decision D-30): a contact to an account, a deal to an account or a
 * contact (its primary contact or a role on the deal), an account to a
 * parent account, two records into one merge — or a record was about to
 * move to another project, which no path may do.
 *
 * The pickers only ever offer records of the same project and the forms
 * never offer the project, so this is reached by a crafted request or a
 * programming error, never by a form used as intended;
 * ProjectConsistencyObserver and RecordMerger throw it before anything is
 * written. `$link` names the link that failed, so a caller that turns the
 * refusal into a validation message knows which field it belongs to. The
 * message is for the log, not the screen.
 */
final class CrossProjectLinkException extends DomainException
{
    public const string LINK_ACCOUNT = 'account';

    public const string LINK_CONTACT = 'contact';

    public const string LINK_PARENT = 'parent';

    public const string LINK_MERGE = 'merge';

    public const string LINK_PROJECT = 'project';

    /**
     * @param  self::LINK_*  $link
     */
    private function __construct(
        public readonly string $link,
        string $message,
    ) {
        parent::__construct($message);
    }

    /** A contact or a deal named an account of another project. */
    public static function account(Model $record, int $accountId): self
    {
        return new self(self::LINK_ACCOUNT, sprintf(
            '%s cannot be linked to account %d: the account belongs to another project.',
            self::describe($record),
            $accountId,
        ));
    }

    /** A deal named a contact of another project. */
    public static function contact(Model $record, int $contactId): self
    {
        return new self(self::LINK_CONTACT, sprintf(
            '%s cannot be linked to contact %d: the contact belongs to another project.',
            self::describe($record),
            $contactId,
        ));
    }

    /** An account named a parent account of another project. */
    public static function parent(Model $record, int $parentId): self
    {
        return new self(self::LINK_PARENT, sprintf(
            '%s cannot be placed under account %d: the parent belongs to another project.',
            self::describe($record),
            $parentId,
        ));
    }

    /** A merge was asked to fold a record into one of another project. */
    public static function merge(Model $keep, Model $duplicate): self
    {
        return new self(self::LINK_MERGE, sprintf(
            '%s cannot absorb %s: the two records belong to different projects.',
            self::describe($keep),
            self::describe($duplicate),
        ));
    }

    /** An existing record's project_id was about to change. */
    public static function moved(Model $record): self
    {
        return new self(self::LINK_PROJECT, sprintf(
            '%s cannot move to another project: project_id is set when a record is created and never changed afterwards.',
            self::describe($record),
        ));
    }

    private static function describe(Model $record): string
    {
        $key = $record->getKey();

        return class_basename($record).' '.($key === null ? '(new)' : (string) $key);
    }
}
