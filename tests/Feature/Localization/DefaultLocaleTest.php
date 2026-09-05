<?php

declare(strict_types=1);

namespace Tests\Feature\Localization;

use App\Models\User;
use BezhanSalleh\LanguageSwitch\Events\LocaleChanged;
use BezhanSalleh\LanguageSwitch\LanguageSwitch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * D-5: Arabic default, English opt-in, the choice remembered per user.
 */
final class DefaultLocaleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_english_browser_still_opens_in_arabic_without_an_explicit_switch(): void
    {
        $this->withHeaders(['Accept-Language' => 'en-US,en;q=0.9']);

        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('dir="rtl"', escape: false);

        $this->assertSame('ar', LanguageSwitch::make()->getPreferredLocale());
        $this->assertSame('ar', app()->getLocale());
    }

    #[Test]
    public function an_explicit_session_locale_is_honoured_and_flips_direction(): void
    {
        $this->withSession(['locale' => 'en'])->withHeaders(['Accept-Language' => 'ar-SA,ar;q=0.9']);

        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('dir="ltr"', escape: false)
            ->assertSee('lang="en"', escape: false);

        $this->assertSame('en', app()->getLocale());
    }

    #[Test]
    public function the_fallback_locale_differs_from_the_default_locale(): void
    {
        $this->assertSame('ar', config('app.locale'));
        $this->assertSame('en', config('app.fallback_locale'));
        $this->assertNotSame(config('app.locale'), config('app.fallback_locale'));
    }

    #[Test]
    public function a_signed_in_users_stored_locale_wins_over_the_browser(): void
    {
        $user = User::factory()->english()->create();

        $this->actingAs($user)
            ->withHeaders(['Accept-Language' => 'ar-SA,ar;q=0.9'])
            ->get('/admin')
            ->assertOk()
            ->assertSee('lang="en"', escape: false);
    }

    #[Test]
    public function switching_the_language_is_remembered_on_the_account(): void
    {
        $user = User::factory()->create(['locale' => 'ar']);

        $this->actingAs($user);
        event(new LocaleChanged('en'));

        $this->assertSame('en', $user->refresh()->locale);

        event(new LocaleChanged('fr'));
        $this->assertSame('en', $user->refresh()->locale, 'unsupported locales are ignored');
    }
}
