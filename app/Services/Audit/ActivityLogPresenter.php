<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Enums\ActivityLogEvent;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Formats audit rows for the ledger table and its detail modal (decision A-5).
 *
 * Attribute diffs written by LogsActivity arrive as {attributes, old};
 * business events written by AuditLogger arrive as flat properties. Both are
 * rendered as label/value rows with labels resolved through
 * lang/{locale}/activity.php (attribute labels fall back to the raw key so a new
 * column never breaks the screen).
 */
final class ActivityLogPresenter
{
    public function __construct(
        private readonly ActivityLog $log,
    ) {}

    public function for(ActivityLog $log): self
    {
        return new self($log);
    }

    public function actionLabel(): string
    {
        $event = ActivityLogEvent::tryFromDescription($this->log->description);

        return $event?->getLabel() ?? (string) $this->log->description;
    }

    public function subjectLabel(): string
    {
        $subject = $this->log->subject;

        if ($subject instanceof Model) {
            $label = $this->labelOf($subject);

            if ($label !== null) {
                return $label;
            }
        }

        $fromProperties = $this->properties()->get('subject_label');

        if (is_string($fromProperties) && $fromProperties !== '') {
            return $fromProperties;
        }

        if ($this->log->subject_type !== null) {
            return __('activity.subject.deleted', [
                'type' => __('activity.subject_types.'.class_basename((string) $this->log->subject_type)),
                'id' => (string) $this->log->subject_id,
            ]);
        }

        return __('activity.placeholders.no_subject');
    }

    public function causerLabel(): string
    {
        $causer = $this->log->causer;

        if ($causer instanceof User) {
            return $causer->name;
        }

        if ($this->log->causer_type !== null && $this->log->causer_id !== null) {
            $name = User::withTrashed()->whereKey($this->log->causer_id)->value('name');

            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        return __('activity.sources.system');
    }

    public function logNameLabel(): string
    {
        return __('activity.log_names.'.$this->log->log_name);
    }

    /**
     * Key/value rows for the detail modal.
     *
     * @return list<array{label: string, value: string}>
     */
    public function detailRows(): array
    {
        $rows = [
            ['label' => __('activity.details.event'), 'value' => $this->actionLabel()],
            ['label' => __('activity.details.subject'), 'value' => $this->subjectLabel()],
            ['label' => __('activity.details.causer'), 'value' => $this->causerLabel()],
            ['label' => __('activity.details.recorded_at'), 'value' => $this->log->created_at?->timezone((string) config('app.timezone'))->format('Y-m-d H:i:s') ?? '—'],
        ];

        return [...$rows, ...$this->changeRows(), ...$this->propertyRows()];
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function changeRows(): array
    {
        $properties = $this->properties();
        $attributes = collect(is_array($properties->get('attributes')) ? $properties->get('attributes') : []);
        $old = collect(is_array($properties->get('old')) ? $properties->get('old') : []);

        $rows = [];

        foreach ($attributes->keys()->merge($old->keys())->unique() as $key) {
            $key = (string) $key;
            $newValue = $this->scalar($attributes->get($key));

            $rows[] = [
                'label' => $this->attributeLabel($key),
                'value' => $old->has($key)
                    ? __('activity.details.from_to', ['old' => $this->scalar($old->get($key)), 'new' => $newValue])
                    : $newValue,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function propertyRows(): array
    {
        $rows = [];

        foreach ($this->properties()->except(['attributes', 'old', 'subject_label'])->all() as $key => $value) {
            $rows[] = [
                'label' => $this->attributeLabel((string) $key),
                'value' => $this->scalar($value),
            ];
        }

        return $rows;
    }

    private function attributeLabel(string $key): string
    {
        $translated = __('activity.attributes.'.$key);

        return $translated === 'activity.attributes.'.$key ? $key : $translated;
    }

    private function labelOf(Model $subject): ?string
    {
        if ($subject instanceof User) {
            return $subject->name;
        }

        $displayName = $subject->getAttribute('display_name');

        if (is_string($displayName) && $displayName !== '') {
            return $displayName;
        }

        foreach (['title', 'name', 'key'] as $attribute) {
            $value = $subject->getAttribute($attribute);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function scalar(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? __('activity.values.yes') : __('activity.values.no');
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return Collection<string, mixed>
     */
    private function properties(): Collection
    {
        $properties = $this->log->properties;

        return $properties instanceof Collection ? $properties : collect();
    }
}
