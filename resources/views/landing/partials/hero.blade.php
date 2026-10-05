@php
    use App\Enums\TaskPriority;
    use Filament\Support\Icons\Heroicon;

    // The product preview is an illustration (D-24): every name and number
    // in it is sample content, and its caption says so. It is drawn from the
    // CRM's own pieces — a handed-out task with its checklist and a comment
    // that mentions a colleague, a sales pipeline and a lead score — and is
    // hidden from assistive technology, which reads the caption instead.
    $items = __('landing.preview.items');
    $ticked = 3;
    $stageCounts = ['qualification' => 12, 'proposal' => 8, 'negotiation' => 5, 'won' => 3];
    $widest = max($stageCounts);
    $score = 82;
@endphp

<section class="crm-landing-hero" aria-labelledby="crm-landing-hero-title">
    <div class="crm-landing-container crm-landing-hero-grid">
        <div class="crm-landing-hero-copy">
            <p class="crm-landing-eyebrow">{{ __('landing.hero.eyebrow') }}</p>

            <h1 id="crm-landing-hero-title" class="crm-landing-hero-title">
                {{ __('landing.hero.title') }}
                <span class="crm-landing-hero-emphasis">{{ __('landing.hero.title_emphasis') }}</span>
            </h1>

            <p class="crm-landing-hero-lead">{{ __('landing.hero.lead') }}</p>

            <div class="crm-landing-actions">
                <a href="{{ $primaryUrl }}" class="crm-landing-btn crm-landing-btn-primary" data-landing-cta="hero">
                    {{ $signedIn ? __('landing.hero.cta_workspaces') : __('landing.hero.cta_sign_in') }}
                </a>

                <a href="#features" class="crm-landing-btn crm-landing-btn-ghost">{{ __('landing.hero.cta_features') }}</a>
            </div>

            @unless ($signedIn)
                <p class="crm-landing-hero-note">{{ __('landing.hero.invite_note') }}</p>
            @endunless
        </div>

        <figure class="crm-landing-preview">
            <div class="crm-landing-window" aria-hidden="true">
                <div class="crm-landing-window-bar">
                    <span class="crm-landing-window-dot"></span>
                    <span class="crm-landing-window-dot"></span>
                    <span class="crm-landing-window-dot"></span>
                    <span class="crm-landing-window-title">{{ __('landing.preview.window_title') }}</span>
                </div>

                <div class="crm-landing-window-body">
                    <div class="crm-landing-task">
                        <div class="crm-landing-task-head">
                            <span class="crm-landing-task-title">{{ __('landing.preview.task_title') }}</span>
                            <span class="crm-landing-badge">{{ TaskPriority::High->getLabel() }}</span>
                        </div>

                        <div class="crm-landing-task-meta">
                            <span class="crm-avatar" style="--crm-avatar-h: 197">{{ mb_substr(__('landing.preview.assignee'), 0, 1) }}</span>
                            <span>{{ __('landing.preview.assignee') }}</span>
                            <span class="crm-landing-task-due">{{ __('landing.preview.task_due') }}</span>
                        </div>

                        <div class="crm-landing-checklist">
                            <div class="crm-landing-checklist-head">
                                <span>{{ __('landing.preview.checklist') }}</span>
                                <span class="crm-ltr crm-tabular">{{ __('landing.preview.progress', ['done' => $ticked, 'total' => count($items)]) }}</span>
                            </div>

                            <span class="crm-landing-progress">
                                <span class="crm-landing-progress-fill" style="inline-size: {{ round($ticked / count($items) * 100) }}%"></span>
                            </span>

                            <ul class="crm-landing-checklist-items" role="list">
                                @foreach ($items as $text)
                                    @php($done = $loop->index < $ticked)

                                    <li @class(['crm-landing-check', 'crm-landing-check-done' => $done])>
                                        <span class="crm-landing-check-box">
                                            @if ($done)
                                                <x-filament::icon :icon="Heroicon::Check" class="crm-landing-check-glyph" />
                                            @endif
                                        </span>
                                        <span>{{ $text }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>

                        <div class="crm-landing-comment">
                            <span class="crm-avatar" style="--crm-avatar-h: 32">{{ mb_substr(__('landing.preview.comment_author'), 0, 1) }}</span>
                            <span class="crm-landing-comment-body">
                                <span class="crm-landing-comment-author">{{ __('landing.preview.comment_author') }}</span>
                                <span><span class="crm-mention">{{ __('landing.preview.comment_mention') }}</span> {{ __('landing.preview.comment_body') }}</span>
                            </span>
                        </div>
                    </div>

                    <div class="crm-landing-side">
                        <div class="crm-landing-mini">
                            <span class="crm-landing-mini-title">{{ __('landing.preview.pipeline') }}</span>

                            <ul class="crm-landing-stages" role="list">
                                @foreach ($stageCounts as $stage => $count)
                                    <li class="crm-landing-stage">
                                        <span class="crm-landing-stage-line">
                                            <span>{{ __('landing.preview.stages.'.$stage) }}</span>
                                            <span class="crm-tabular">{{ $count }}</span>
                                        </span>
                                        <span class="crm-landing-stage-bar">
                                            <span class="crm-landing-stage-fill" style="inline-size: {{ round($count / $widest * 100) }}%"></span>
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>

                        <div class="crm-landing-mini">
                            <span class="crm-landing-mini-title">{{ __('landing.preview.score') }}</span>

                            <span class="crm-landing-score">
                                <span class="crm-landing-score-value crm-tabular">{{ $score }}</span>
                                <span class="crm-landing-progress">
                                    <span class="crm-landing-progress-fill" style="inline-size: {{ $score }}%"></span>
                                </span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <figcaption class="crm-landing-preview-caption">{{ __('landing.preview.caption') }}</figcaption>
        </figure>
    </div>
</section>
