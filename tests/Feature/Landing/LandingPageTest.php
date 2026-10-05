<?php

declare(strict_types=1);

namespace Tests\Feature\Landing;

use App\Enums\CrmRole;
use App\Enums\UserStatus;
use App\Enums\WorkspacePanel;
use App\Support\Filament\PanelUrl;
use App\Support\Landing\LandingFacts;
use BezhanSalleh\LanguageSwitch\Http\Middleware\SwitchLanguageLocale;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The public landing page at the site root (decision D-24): bilingual,
 * right-to-left and left-to-right, a guest's way in is the one sign-in page
 * and a signed-in visitor's the workspace screen, the facts are the code's
 * own numbers, the head carries title, description and Open Graph, the
 * language and theme switches are there, panels stay out of search engines,
 * and the page's styles keep the motion and direction rules of the theme.
 */
final class LandingPageTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const THEME = 'resources/css/filament/admin/theme.css';

    #[Test]
    public function a_guest_sees_the_landing_page_in_arabic_and_right_to_left_by_default(): void
    {
        $html = $this->get('/')
            ->assertOk()
            ->assertSee('lang="ar"', escape: false)
            ->assertSee('dir="rtl"', escape: false)
            ->assertSee('مبيعاتك ومهام فريقك')
            ->assertSee('في مكان واحد')
            ->assertSee('الأسئلة الشائعة')
            ->assertSee('صورة توضيحية ببيانات افتراضية')
            ->assertSee('الحسابات بدعوة من مدير النظام.')
            ->getContent();

        $this->assertSame(['nav', 'hero', 'closing'], array_keys($this->ctaLinks((string) $html)));

        foreach ($this->ctaLinks((string) $html) as $where => [$href, $text]) {
            $this->assertSame($this->signInUrl(), $href, "the {$where} call to action of a guest does not lead to the sign-in page");
            $this->assertSame('تسجيل الدخول', $text);
        }

        $this->assertStringNotContainsString('href="'.PanelUrl::workspaceScreen().'"', (string) $html, 'a guest is offered the workspace screen');
    }

    #[Test]
    public function an_english_reader_gets_the_page_in_english_and_left_to_right(): void
    {
        $html = $this->withSession(['locale' => 'en'])->get('/')
            ->assertOk()
            ->assertSee('lang="en"', escape: false)
            ->assertSee('dir="ltr"', escape: false)
            ->assertSee('in one place')
            ->assertSee('Frequently asked questions')
            ->assertSee('Illustration with sample data')
            ->assertDontSee('مبيعاتك ومهام فريقك')
            ->getContent();

        foreach ($this->ctaLinks((string) $html) as [$href, $text]) {
            $this->assertSame($this->signInUrl(), $href);
            $this->assertSame('Sign in', $text);
        }
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function signedInReaders(): array
    {
        return [
            'arabic' => ['ar', 'rtl', 'مساحات عملي', 'الانتقال إلى مساحات عملي'],
            'english' => ['en', 'ltr', 'My workspaces', 'Go to my workspaces'],
        ];
    }

    #[Test]
    #[DataProvider('signedInReaders')]
    public function a_signed_in_visitor_is_offered_the_workspace_screen_instead_of_the_sign_in_page(string $locale, string $direction, string $navLabel, string $actionLabel): void
    {
        $this->seedAccess();

        $html = $this->actingAs($this->makeUser(CrmRole::Employee, ['locale' => $locale]))
            ->withSession(['locale' => $locale])
            ->get('/')
            ->assertOk()
            ->assertSee('lang="'.$locale.'"', escape: false)
            ->assertSee('dir="'.$direction.'"', escape: false)
            ->assertDontSee(__('landing.hero.invite_note', [], $locale))
            ->getContent();

        $links = $this->ctaLinks((string) $html);

        $this->assertSame(['nav', 'hero', 'closing'], array_keys($links));
        $this->assertSame([PanelUrl::workspaceScreen(), $navLabel], $links['nav']);
        $this->assertSame([PanelUrl::workspaceScreen(), $actionLabel], $links['hero']);
        $this->assertSame([PanelUrl::workspaceScreen(), $actionLabel], $links['closing']);
        $this->assertStringNotContainsString('href="'.$this->signInUrl().'"', (string) $html, 'a signed-in visitor is still offered the sign-in page');
    }

    #[Test]
    public function an_account_that_may_no_longer_sign_in_is_not_offered_the_workspace_screen(): void
    {
        $this->seedAccess();

        foreach ([UserStatus::Disabled, UserStatus::Pending] as $status) {
            $user = $this->makeUser(CrmRole::Employee);
            $user->forceFill(['status' => $status])->save();

            // Its session outlived the change; the workspace screen would answer 403.
            $html = (string) $this->actingAs($user)->get('/')->assertOk()->getContent();

            $this->assertSame(['nav', 'hero', 'closing'], array_keys($this->ctaLinks($html)));

            foreach ($this->ctaLinks($html) as $where => [$href]) {
                $this->assertSame($this->signInUrl(), $href, "the {$where} call to action of a {$status->value} account leads to the workspace screen");
            }
        }
    }

    #[Test]
    public function the_head_carries_the_title_the_description_and_the_open_graph_card_in_the_readers_language(): void
    {
        $arabic = (string) $this->get('/')->getContent();

        $this->assertMatchesRegularExpression('/<title>\s*'.preg_quote('إدارة المبيعات والمهام في نظام واحد', '/').'\s*-\s*'.preg_quote(__('app.name', [], 'ar'), '/').'\s*<\/title>/u', $arabic);
        $this->assertStringContainsString('<meta name="description" content="'.e(__('landing.meta.description', [], 'ar')).'"', $arabic);
        $this->assertStringContainsString('<meta property="og:title" content="'.e(__('landing.meta.title', [], 'ar')).'"', $arabic);
        $this->assertStringContainsString('<meta property="og:locale" content="ar_SA"', $arabic);
        $this->assertStringContainsString('<meta property="og:locale:alternate" content="en_US"', $arabic);
        $this->assertStringContainsString('<meta property="og:url" content="'.route('landing').'"', $arabic);
        $this->assertStringContainsString('<link rel="canonical" href="'.route('landing').'"', $arabic);

        $english = (string) $this->withSession(['locale' => 'en'])->get('/')->getContent();

        $this->assertMatchesRegularExpression('/<title>\s*Sales and tasks in one system\s*-\s*'.preg_quote(__('app.name', [], 'en'), '/').'\s*<\/title>/', $english);
        $this->assertStringContainsString('<meta property="og:locale" content="en_US"', $english);
        $this->assertStringContainsString('<meta property="og:description" content="'.e(__('landing.meta.description', [], 'en')).'"', $english);

        // Each language has an address of its own, canonical to itself, and
        // both pages announce both addresses, so the English page is
        // indexable instead of pointing search engines at the Arabic one.
        $englishAddress = route('landing', ['locale' => 'en']);
        $this->assertStringContainsString('<link rel="canonical" href="'.e($englishAddress).'"', $english);
        $this->assertStringContainsString('<meta property="og:url" content="'.e($englishAddress).'"', $english);

        foreach ([$arabic, $english] as $html) {
            $this->assertStringContainsString('<link rel="alternate" hreflang="ar" href="'.route('landing').'"', $html);
            $this->assertStringContainsString('<link rel="alternate" hreflang="en" href="'.e($englishAddress).'"', $html);
            $this->assertStringContainsString('<link rel="alternate" hreflang="x-default" href="'.route('landing').'"', $html);
        }

        // A crawler — no session, no cookie — fetching the English address gets English.
        $this->flushSession();
        $this->get($englishAddress)->assertOk()->assertSee('lang="en"', escape: false)->assertSee('<link rel="canonical" href="'.e($englishAddress).'"', escape: false);
    }

    #[Test]
    public function the_landing_head_tags_render_on_the_landing_page_only(): void
    {
        $this->get('/app/login')
            ->assertOk()
            ->assertDontSee('og:title', escape: false)
            ->assertDontSee('rel="canonical"', escape: false);
    }

    #[Test]
    public function the_facts_strip_shows_the_numbers_the_code_holds(): void
    {
        $expected = [
            'workspaces' => count(WorkspacePanel::cases()),
            'roles' => count(CrmRole::cases()),
            'languages' => count((array) config('app.locales')),
            'audit_days' => (int) config('crm.audit.retention_days'),
        ];

        $facts = [];

        foreach (LandingFacts::all() as $fact) {
            $facts[$fact->key] = $fact->value;
        }

        $this->assertSame($expected, $facts);

        $html = (string) $this->get('/')->getContent();

        foreach ($expected as $key => $value) {
            $this->assertMatchesRegularExpression(
                '/data-fact="'.$key.'">\s*<dt class="crm-landing-fact-label">'.preg_quote(e(trans_choice('landing.facts.'.$key.'.label', $value, [], 'ar')), '/').'<\/dt>\s*<dd[^>]*data-fact-value="'.$value.'">'.$value.'<\/dd>/u',
                $html,
                "the {$key} fact does not show {$value} with its counted noun",
            );
        }
    }

    #[Test]
    public function every_counted_noun_of_the_facts_strip_has_its_arabic_zero_form(): void
    {
        // CLAUDE.md §3: Arabic counted nouns spell out {0}/{1}/{2}/[3,10]/[11,99]/[100,*].
        // The audit retention comes from the environment, so 0 can reach the strip.
        foreach (['workspaces', 'roles', 'languages', 'audit_days'] as $key) {
            $line = (string) __('landing.facts.'.$key.'.label', [], 'ar');
            $segments = explode('|', $line);

            $this->assertCount(6, $segments, "landing.facts.{$key}.label lacks an Arabic plural form");
            $this->assertSame(['{0}', '{1}', '{2}', '[3,10]', '[11,99]', '[100,*]'], array_map(static fn (string $segment): string => strtok($segment, ' ') ?: '', $segments));
            $this->assertSame(trim(substr($segments[0], strlen('{0}'))), trans_choice('landing.facts.'.$key.'.label', 0, [], 'ar'));
        }

        $this->assertSame('days', trim(trans_choice('landing.facts.audit_days.label', 0, [], 'en')));
    }

    #[Test]
    public function a_changed_retention_setting_reaches_the_facts_strip(): void
    {
        config(['crm.audit.retention_days' => 365]);

        $this->get('/')->assertSee('data-fact-value="365"', escape: false);
    }

    #[Test]
    public function the_four_workspaces_are_shown_in_order_with_their_own_names_and_descriptions(): void
    {
        $html = (string) $this->get('/')->getContent();
        $positions = [];

        foreach (WorkspacePanel::ordered() as $workspace) {
            $position = strpos($html, 'data-workspace="'.$workspace->id().'"');
            $this->assertNotFalse($position, "the {$workspace->id()} workspace is missing");
            $positions[] = $position;

            $this->assertStringContainsString(e($workspace->getLabel()), $html);
            $this->assertStringContainsString(e($workspace->getDescription()), $html);
        }

        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'the workspaces are not in WorkspacePanel order');

        // Information only: the landing page never links into a workspace.
        foreach (WorkspacePanel::ordered() as $workspace) {
            $this->assertStringNotContainsString('href="'.PanelUrl::panelHome($workspace).'"', $html);
        }
    }

    #[Test]
    public function the_language_switch_offers_the_other_language_named_in_itself(): void
    {
        $this->get('/')
            ->assertSee('action="'.route('landing.language').'"', escape: false)
            ->assertSee('name="locale" value="en"', escape: false)
            ->assertSee('<span lang="en">English</span>', escape: false)
            ->assertSee('aria-label="تغيير اللغة إلى English"', escape: false)
            ->assertDontSee('name="locale" value="ar"', escape: false);

        $this->withSession(['locale' => 'en'])->get('/')
            ->assertSee('name="locale" value="ar"', escape: false)
            ->assertSee('<span lang="ar">العربية</span>', escape: false)
            ->assertDontSee('name="locale" value="en"', escape: false);
    }

    #[Test]
    public function switching_the_language_stores_it_where_the_panels_read_it_and_shows_the_page_in_it(): void
    {
        $this->post(route('landing.language'), ['locale' => 'en'])
            ->assertRedirect(route('landing'))
            ->assertSessionHas('locale', 'en')
            ->assertCookie('filament_language_switch_locale', 'en');

        $this->get('/')->assertSee('lang="en"', escape: false)->assertSee('in one place');
        $this->get('/app/login')->assertSee('lang="en"', escape: false);
    }

    #[Test]
    public function a_signed_in_readers_choice_is_saved_on_the_account(): void
    {
        $this->seedAccess();
        $user = $this->makeUser(CrmRole::SalesRep, ['locale' => 'ar']);

        $this->actingAs($user)->post(route('landing.language'), ['locale' => 'en'])->assertRedirect(route('landing'));

        $this->assertSame('en', $user->fresh()?->locale);
    }

    #[Test]
    public function only_an_interface_language_is_accepted(): void
    {
        $this->from('/')->post(route('landing.language'), ['locale' => 'fr'])
            ->assertRedirect('/')
            ->assertSessionHasErrors('locale')
            ->assertSessionMissing('locale');
    }

    #[Test]
    public function the_theme_switch_follows_filaments_theme_contract_and_waits_for_javascript(): void
    {
        $html = (string) $this->get('/')->getContent();

        $this->assertMatchesRegularExpression('/<button[^>]*data-landing-theme-toggle[^>]*>/s', $html);
        preg_match('/<button[^>]*data-landing-theme-toggle[^>]*>/s', $html, $button);

        $this->assertStringContainsString('x-cloak', $button[0], 'without JavaScript the switch would show and do nothing');
        $this->assertStringContainsString("const next = this.\$store.theme === 'dark' ? 'light' : 'dark'", $button[0]);
        // Switching to the device's own theme stores "system", so the default comes back.
        $this->assertStringContainsString("window.matchMedia('(prefers-color-scheme: dark)')", $button[0]);
        $this->assertStringContainsString("this.\$dispatch('theme-changed', next === device ? 'system' : next)", $button[0]);
        $this->assertStringContainsString('x-on:click="toggle()"', $button[0]);
        $this->assertStringContainsString('aria-pressed=', $button[0]);
        $this->assertStringContainsString('aria-label="'.e(__('landing.nav.theme', [], 'ar')).'"', $button[0]);
    }

    #[Test]
    public function the_landing_route_sets_up_the_home_panel_and_the_readers_language(): void
    {
        $route = Route::getRoutes()->getByName('landing');

        $this->assertNotNull($route);
        $this->assertSame('/', '/'.ltrim($route->uri(), '/'));

        $middleware = $route->gatherMiddleware();

        $this->assertContains('panel:'.WorkspacePanel::HOME, $middleware, 'without SetUpPanel the page has no theme, font or sign-in address');
        $this->assertContains(SwitchLanguageLocale::class, $middleware, 'without the locale middleware the page ignores the language switch');
        $this->assertContains('web', $middleware);
    }

    #[Test]
    public function only_the_landing_page_may_be_indexed_and_every_panel_says_noindex(): void
    {
        $rules = array_map('trim', file(public_path('robots.txt')) ?: []);

        $this->assertContains('User-agent: *', $rules);
        $this->assertContains('Allow: /', $rules);

        // Crawlers must be let in to read the noindex: a Disallow would leave
        // a linked address listable and its header unseen.
        foreach (array_keys(Filament::getPanels()) as $id) {
            $this->assertNotContains('Disallow: /'.Filament::getPanel($id)->getPath(), $rules, "crawlers could never see the {$id} panel's noindex");
        }

        $this->assertNotContains('Disallow: /', $rules, 'the whole site, the landing page included, is hidden from search engines');

        $this->get('/')->assertOk()->assertHeaderMissing('X-Robots-Tag');
        $this->get('/app/login')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/app/password-reset/request')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        // A guest at any panel is redirected (a redirect is never indexed) to
        // the sign-in page above, which says noindex; a signed-in reader's
        // pages say it too.
        foreach (array_keys(Filament::getPanels()) as $id) {
            $this->get('/'.Filament::getPanel($id)->getPath())->assertRedirect(Filament::getPanel(WorkspacePanel::HOME)->getLoginUrl());
        }

        $this->seedAccess();
        $admin = $this->superAdmin();

        foreach (WorkspacePanel::ordered() as $workspace) {
            $this->actingAs($admin)->get('/'.$workspace->path())->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        }

        $this->actingAs($admin)->get(PanelUrl::workspaceScreen())->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    #[Test]
    public function the_landing_views_carry_no_hardcoded_user_facing_text(): void
    {
        $offenders = [];

        foreach (Finder::create()->files()->in(resource_path('views/landing'))->name('*.blade.php') as $file) {
            $source = $file->getContents();

            preg_match_all('/\b(aria-label|alt|title|placeholder|content)="([^"]*)"/', $source, $attributes, PREG_SET_ORDER);

            foreach ($attributes as [, $name, $value]) {
                if ($value !== '' && ! str_starts_with($value, '{{') && in_array($name, ['aria-label', 'alt', 'title', 'placeholder'], true)) {
                    $offenders[] = $file->getRelativePathname().": {$name}=\"{$value}\"";
                }
            }

            // Strip, in order: Blade comments, @php blocks, echoes, Blade
            // directives with their (nested) arguments, then whole tags —
            // quoted attribute values may hold ">" ($a->b) — so what is left
            // is the text a reader would see.
            $text = (string) preg_replace(
                [
                    '/\{\{--.*?--\}\}/s',
                    '/@php\b(?!\s*\().*?@endphp/s',
                    '/\{\{.*?\}\}/s',
                    '/\{!!.*?!!\}/s',
                    '/@[a-z]+\s*(\((?:[^()]++|(?1))*\))?/i',
                    '/<(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/s',
                ],
                '',
                $source,
            );

            if (preg_match_all('/\p{L}[\p{L}\s]*/u', $text, $words) > 0) {
                $offenders[] = $file->getRelativePathname().': '.implode(' | ', array_map('trim', $words[0]));
            }
        }

        $this->assertSame([], $offenders, "user-facing text written into the landing views instead of lang/{ar,en}/landing.php:\n".implode("\n", $offenders));
    }

    #[Test]
    public function the_landing_styles_move_only_behind_the_no_preference_guard_within_the_motion_window(): void
    {
        $section = $this->landingStyles();
        $guard = '@media (prefers-reduced-motion: no-preference)';

        $this->assertStringContainsString($guard, $section, 'the landing section lost its motion guard');

        [$still, $motion] = explode($guard, $section, 2);

        foreach (['animation', 'transition', '@keyframes', 'scroll-behavior'] as $needle) {
            $this->assertStringNotContainsString($needle, $still, "a landing [{$needle}] sits outside the prefers-reduced-motion: no-preference guard");
        }

        $this->assertSame(1, substr_count($section, $guard), 'the landing motion is one guarded block at the end of the section');

        preg_match_all('/\b(animation|transition):\s*([^;]+);/', $motion, $declarations, PREG_SET_ORDER);
        $this->assertNotEmpty($declarations, 'sanity: the landing has motion to check');

        foreach ($declarations as [$declaration, $property, $value]) {
            foreach (explode(',', $property === 'transition' ? $value : (string) preg_replace('/cubic-bezier\([^)]*\)/', '', $value)) as $part) {
                if (preg_match('/(\d*\.?\d+)(ms|s)\b/', $part, $time) !== 1) {
                    continue;
                }

                $milliseconds = (float) $time[1] * ($time[2] === 's' ? 1000 : 1);

                $this->assertGreaterThanOrEqual(140, $milliseconds, "too quick to read: {$declaration}");
                $this->assertLessThanOrEqual(450, $milliseconds, "slower than the A-9 window: {$declaration}");
            }
        }

        preg_match_all('/\btransform:\s*([^;]+);/', $section, $transforms);

        foreach ($transforms[1] as $transform) {
            $this->assertMatchesRegularExpression('/^(?:\s*(?:none|translateY\([^)]*\)|scale[XY]?\([^)]*\)))+\s*$/', $transform, "only translateY and scale move (A-9): transform: {$transform}");
        }
    }

    #[Test]
    public function the_landing_styles_are_visible_at_rest_and_direction_neutral(): void
    {
        $section = $this->landingStyles();
        $still = explode('@media (prefers-reduced-motion: no-preference)', $section, 2)[0];

        $this->assertDoesNotMatchRegularExpression('/opacity:\s*0(?:\.0*)?\s*;/', $still, 'content hidden at rest would stay hidden without JavaScript or motion');
        $this->assertDoesNotMatchRegularExpression('/visibility:\s*hidden/', $still);
        $this->assertStringNotContainsString('[dir', $section, 'a [dir] exception: use logical properties');
        $this->assertDoesNotMatchRegularExpression(
            '/(?<![-\w])(margin|padding|border)-(left|right)\b|(?<![-\w])(left|right)\s*:|text-align:\s*(left|right)|float:\s*(left|right)/',
            $section,
            'a physical left/right property: use the logical one',
        );
        $this->assertMatchesRegularExpression('/\.crm-landing\s*\{[^}]*overflow-x:\s*clip/', $section, 'the page lost its guard against horizontal scrolling');
        $this->assertMatchesRegularExpression('/\.crm-landing a:focus-visible/', $section, 'keyboard focus is not drawn on the landing page');
    }

    /** The landing section of the shared theme, between its markers. */
    private function landingStyles(): string
    {
        $css = (string) file_get_contents(base_path(self::THEME));
        $start = strpos($css, 'landing:start');
        $end = strpos($css, 'landing:end');

        $this->assertNotFalse($start, 'the landing section start marker is missing from the theme');
        $this->assertNotFalse($end, 'the landing section end marker is missing from the theme');

        // From the comment that opens the section; comments may name what the
        // rules avoid, so only the rules are read.
        $start = (int) strrpos(substr($css, 0, $start), '/*');

        return (string) preg_replace('#/\*.*?\*/#s', '', substr($css, $start, $end - $start));
    }

    private function signInUrl(): string
    {
        return (string) Filament::getPanel(WorkspacePanel::HOME)->getLoginUrl();
    }

    /**
     * The page's calls to action, keyed by where they sit: [href, text].
     *
     * @return array<string, array{string, string}>
     */
    private function ctaLinks(string $html): array
    {
        preg_match_all('/<a href="([^"]*)"[^>]*data-landing-cta="([a-z]+)"[^>]*>(.*?)<\/a>/s', $html, $matches, PREG_SET_ORDER);

        $links = [];

        foreach ($matches as [, $href, $where, $text]) {
            $links[$where] = [html_entity_decode($href), trim(html_entity_decode($text))];
        }

        return $links;
    }
}
