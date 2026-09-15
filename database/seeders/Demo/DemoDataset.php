<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\AccountType;
use App\Enums\CompanySize;
use App\Enums\CrmRole;

/**
 * The bilingual Saudi dataset `app:demo-data` builds from (step 13).
 *
 * This is record content — company, people and deal names as a Saudi sales
 * team would type them, some in Arabic and some in English — not interface
 * text, so it is data here rather than translation keys. Every e-mail domain
 * ends in the reserved `.example` top-level domain and every phone number is
 * a fictitious 05 mobile number: nothing here reaches a real mailbox.
 */
final class DemoDataset
{
    public const USER_DOMAIN = 'demo.crm.test';

    /**
     * The demo users: one per seeded role, plus a second manager and a second
     * rep so each of the two teams has a manager and a member.
     *
     * @return list<array{key: string, role: CrmRole, name: string, locale: string, team: ?string, manages: bool}>
     */
    public static function users(): array
    {
        return [
            ['key' => 'super_admin', 'role' => CrmRole::SuperAdmin, 'name' => 'عبدالرحمن السالم', 'locale' => 'ar', 'team' => null, 'manages' => false],
            ['key' => 'admin', 'role' => CrmRole::Admin, 'name' => 'Huda Al-Mansour', 'locale' => 'en', 'team' => null, 'manages' => false],
            ['key' => 'sales_manager', 'role' => CrmRole::SalesManager, 'name' => 'فيصل القحطاني', 'locale' => 'ar', 'team' => 'riyadh', 'manages' => true],
            ['key' => 'sales_rep', 'role' => CrmRole::SalesRep, 'name' => 'نوف الدوسري', 'locale' => 'ar', 'team' => 'riyadh', 'manages' => false],
            ['key' => 'support', 'role' => CrmRole::Support, 'name' => 'Omar Haddad', 'locale' => 'en', 'team' => null, 'manages' => false],
            ['key' => 'read_only', 'role' => CrmRole::ReadOnly, 'name' => 'ريم العتيبي', 'locale' => 'ar', 'team' => null, 'manages' => false],
            ['key' => 'sales_manager2', 'role' => CrmRole::SalesManager, 'name' => 'Khalid Bakr', 'locale' => 'en', 'team' => 'jeddah', 'manages' => true],
            ['key' => 'sales_rep2', 'role' => CrmRole::SalesRep, 'name' => 'Lina Farouk', 'locale' => 'en', 'team' => 'jeddah', 'manages' => false],
        ];
    }

    /**
     * @return array<string, array{name_ar: string, name_en: string, sort: int}>
     */
    public static function teams(): array
    {
        return [
            'riyadh' => ['name_ar' => 'فريق مبيعات الرياض (تجريبي)', 'name_en' => 'Riyadh Sales (demo)', 'sort' => 10],
            'jeddah' => ['name_ar' => 'فريق مبيعات جدة (تجريبي)', 'name_en' => 'Jeddah Sales (demo)', 'sort' => 20],
        ];
    }

    /**
     * @return list<array{code: string, name_ar: string, name_en: string, unit_price: string}>
     */
    public static function products(): array
    {
        return [
            ['code' => 'DEMO-LIC', 'name_ar' => 'ترخيص نظام إدارة العملاء - سنوي (تجريبي)', 'name_en' => 'CRM licence - annual (demo)', 'unit_price' => '4800.00'],
            ['code' => 'DEMO-ONB', 'name_ar' => 'خدمة الإعداد والتهيئة (تجريبي)', 'name_en' => 'Onboarding and setup (demo)', 'unit_price' => '7500.00'],
            ['code' => 'DEMO-TRN', 'name_ar' => 'ورشة تدريب الفريق (تجريبي)', 'name_en' => 'Team training workshop (demo)', 'unit_price' => '3200.00'],
            ['code' => 'DEMO-SUP', 'name_ar' => 'باقة الدعم المميز (تجريبي)', 'name_en' => 'Premium support plan (demo)', 'unit_price' => '2400.00'],
            ['code' => 'DEMO-INT', 'name_ar' => 'تكامل الأنظمة (تجريبي)', 'name_en' => 'Systems integration (demo)', 'unit_price' => '12000.00'],
            ['code' => 'DEMO-POS', 'name_ar' => 'جهاز نقاط البيع (تجريبي)', 'name_en' => 'Point-of-sale terminal (demo)', 'unit_price' => '1850.00'],
        ];
    }

    /**
     * Cities with their region, in both languages.
     *
     * @return list<array{ar: string, en: string, region_ar: string, region_en: string, postal: string}>
     */
    public static function cities(): array
    {
        return [
            ['ar' => 'الرياض', 'en' => 'Riyadh', 'region_ar' => 'منطقة الرياض', 'region_en' => 'Riyadh Region', 'postal' => '12211'],
            ['ar' => 'جدة', 'en' => 'Jeddah', 'region_ar' => 'منطقة مكة المكرمة', 'region_en' => 'Makkah Region', 'postal' => '23433'],
            ['ar' => 'الدمام', 'en' => 'Dammam', 'region_ar' => 'المنطقة الشرقية', 'region_en' => 'Eastern Province', 'postal' => '32241'],
            ['ar' => 'الخبر', 'en' => 'Khobar', 'region_ar' => 'المنطقة الشرقية', 'region_en' => 'Eastern Province', 'postal' => '34424'],
            ['ar' => 'المدينة المنورة', 'en' => 'Madinah', 'region_ar' => 'منطقة المدينة المنورة', 'region_en' => 'Madinah Region', 'postal' => '42311'],
            ['ar' => 'بريدة', 'en' => 'Buraidah', 'region_ar' => 'منطقة القصيم', 'region_en' => 'Qassim Region', 'postal' => '52361'],
        ];
    }

    /**
     * Twelve accounts: mixed types, industries (seeded English names) and languages.
     *
     * @return list<array{name: string, arabic: bool, domain: string, industry: string, type: AccountType, size: CompanySize, city: int, owner: string}>
     */
    public static function accounts(): array
    {
        return [
            ['name' => 'شركة الأفق للحلول التقنية', 'arabic' => true, 'domain' => 'alofuq-tech', 'industry' => 'Technology', 'type' => AccountType::Customer, 'size' => CompanySize::From51To200, 'city' => 0, 'owner' => 'sales_rep'],
            ['name' => 'Najd Logistics Company', 'arabic' => false, 'domain' => 'najd-logistics', 'industry' => 'Logistics', 'type' => AccountType::Prospect, 'size' => CompanySize::From201To500, 'city' => 0, 'owner' => 'sales_manager'],
            ['name' => 'مجموعة الواحة التجارية', 'arabic' => true, 'domain' => 'alwaha-group', 'industry' => 'Retail', 'type' => AccountType::Customer, 'size' => CompanySize::From501To1000, 'city' => 1, 'owner' => 'sales_rep2'],
            ['name' => 'Red Sea Hospitality Group', 'arabic' => false, 'domain' => 'redsea-hospitality', 'industry' => 'Hospitality', 'type' => AccountType::Prospect, 'size' => CompanySize::From201To500, 'city' => 1, 'owner' => 'sales_manager2'],
            ['name' => 'مصنع الخليج للصناعات البلاستيكية', 'arabic' => true, 'domain' => 'gulf-plastics', 'industry' => 'Manufacturing', 'type' => AccountType::Prospect, 'size' => CompanySize::From51To200, 'city' => 2, 'owner' => 'sales_rep'],
            ['name' => 'Al Noor Medical Center', 'arabic' => false, 'domain' => 'alnoor-medical', 'industry' => 'Healthcare', 'type' => AccountType::Customer, 'size' => CompanySize::From11To50, 'city' => 0, 'owner' => 'sales_rep'],
            ['name' => 'شركة البناء المتقدم للمقاولات', 'arabic' => true, 'domain' => 'advanced-build', 'industry' => 'Construction', 'type' => AccountType::Prospect, 'size' => CompanySize::Above1000, 'city' => 1, 'owner' => 'sales_rep2'],
            ['name' => 'Summit Real Estate Development', 'arabic' => false, 'domain' => 'summit-realestate', 'industry' => 'Real Estate', 'type' => AccountType::Partner, 'size' => CompanySize::From51To200, 'city' => 3, 'owner' => 'sales_manager'],
            ['name' => 'أكاديمية المعرفة للتدريب', 'arabic' => true, 'domain' => 'almaarifa-academy', 'industry' => 'Education', 'type' => AccountType::Prospect, 'size' => CompanySize::From11To50, 'city' => 4, 'owner' => 'sales_rep2'],
            ['name' => 'Tamr Foods', 'arabic' => false, 'domain' => 'tamr-foods', 'industry' => 'Food and Beverage', 'type' => AccountType::Customer, 'size' => CompanySize::From201To500, 'city' => 5, 'owner' => 'sales_rep'],
            ['name' => 'شركة طاقة المستقبل', 'arabic' => true, 'domain' => 'future-energy', 'industry' => 'Energy', 'type' => AccountType::Prospect, 'size' => CompanySize::From501To1000, 'city' => 2, 'owner' => 'sales_manager2'],
            ['name' => 'Masar Financial Advisory', 'arabic' => false, 'domain' => 'masar-advisory', 'industry' => 'Finance', 'type' => AccountType::Other, 'size' => CompanySize::From1To10, 'city' => 0, 'owner' => 'sales_manager'],
        ];
    }

    /**
     * People, as [display, ascii] pairs for the e-mail local part.
     *
     * @return array{ar_first: list<array{string, string}>, ar_last: list<array{string, string}>, en_first: list<array{string, string}>, en_last: list<array{string, string}>}
     */
    public static function people(): array
    {
        return [
            'ar_first' => [
                ['محمد', 'mohammed'], ['سارة', 'sarah'], ['عبدالله', 'abdullah'], ['نورة', 'noura'], ['خالد', 'khalid'],
                ['هيفاء', 'haifa'], ['فهد', 'fahad'], ['لمى', 'lama'], ['سلطان', 'sultan'], ['منيرة', 'munira'],
            ],
            'ar_last' => [
                ['الشهري', 'alshehri'], ['الحربي', 'alharbi'], ['الغامدي', 'alghamdi'], ['المطيري', 'almutairi'], ['الزهراني', 'alzahrani'],
                ['العنزي', 'alanazi'], ['السبيعي', 'alsubaie'], ['الشمري', 'alshammari'], ['الرشيدي', 'alrashidi'], ['الجهني', 'aljuhani'],
            ],
            'en_first' => [
                ['Ahmed', 'ahmed'], ['Emily', 'emily'], ['Karim', 'karim'], ['Priya', 'priya'], ['James', 'james'],
                ['Hana', 'hana'], ['Daniel', 'daniel'], ['Nadia', 'nadia'], ['Tariq', 'tariq'], ['Laura', 'laura'],
            ],
            'en_last' => [
                ['Saleh', 'saleh'], ['Brooks', 'brooks'], ['Mansour', 'mansour'], ['Nair', 'nair'], ['Carter', 'carter'],
                ['Yusuf', 'yusuf'], ['Weber', 'weber'], ['Rahman', 'rahman'], ['Aziz', 'aziz'], ['Chen', 'chen'],
            ],
        ];
    }

    /**
     * Companies behind the leads (distinct from the accounts).
     *
     * @return list<array{name: string, arabic: bool, domain: string}>
     */
    public static function leadCompanies(): array
    {
        return [
            ['name' => 'مؤسسة رواد الخليج التجارية', 'arabic' => true, 'domain' => 'rowad-gulf'],
            ['name' => 'Horizon Dental Clinics', 'arabic' => false, 'domain' => 'horizon-dental'],
            ['name' => 'شركة سحاب للاتصالات', 'arabic' => true, 'domain' => 'sahab-telecom'],
            ['name' => 'Oasis Car Rental', 'arabic' => false, 'domain' => 'oasis-rental'],
            ['name' => 'مكتب الإتقان للاستشارات الهندسية', 'arabic' => true, 'domain' => 'itqan-engineering'],
            ['name' => 'Desert Rose Events', 'arabic' => false, 'domain' => 'desertrose-events'],
            ['name' => 'مخابز السنبلة', 'arabic' => true, 'domain' => 'sunbula-bakeries'],
            ['name' => 'Falcon Security Services', 'arabic' => false, 'domain' => 'falcon-security'],
            ['name' => 'شركة المسار السريع للشحن', 'arabic' => true, 'domain' => 'fastpath-shipping'],
            ['name' => 'Palm Valley Schools', 'arabic' => false, 'domain' => 'palmvalley-schools'],
            ['name' => 'مصنع النخبة للأثاث', 'arabic' => true, 'domain' => 'nukhba-furniture'],
            ['name' => 'Crescent Pharmacy Chain', 'arabic' => false, 'domain' => 'crescent-pharmacy'],
            ['name' => 'شركة الريادة العقارية', 'arabic' => true, 'domain' => 'riyada-realestate'],
            ['name' => 'Arabian Coffee Roasters', 'arabic' => false, 'domain' => 'arabian-roasters'],
            ['name' => 'مجموعة الإعمار الحديثة', 'arabic' => true, 'domain' => 'modern-emaar'],
            ['name' => 'BlueWave Water Solutions', 'arabic' => false, 'domain' => 'bluewave-water'],
        ];
    }

    /**
     * @return array{ar: list<string>, en: list<string>}
     */
    public static function jobTitles(): array
    {
        return [
            'ar' => ['مدير المشتريات', 'المدير التنفيذي', 'مدير تقنية المعلومات', 'مدير العمليات', 'مدير المبيعات', 'المدير المالي'],
            'en' => ['Procurement Manager', 'Chief Executive Officer', 'IT Director', 'Operations Manager', 'Head of Sales', 'Finance Director'],
        ];
    }

    /**
     * Deal titles, each in the language of the account it is offered to.
     *
     * @return array{ar: list<string>, en: list<string>}
     */
    public static function dealTitles(): array
    {
        return [
            'ar' => ['توريد تراخيص النظام للفروع', 'مشروع أتمتة المبيعات', 'عقد الدعم الفني السنوي', 'تجهيز نقاط البيع للمعارض', 'ترقية منصة خدمة العملاء'],
            'en' => ['Branch licence rollout', 'Sales automation project', 'Annual support renewal', 'Point-of-sale refresh', 'Customer service platform upgrade'],
        ];
    }

    /**
     * Activity subject lines and bodies per kind, in both languages.
     *
     * @return array<string, array{ar: list<array{string, string}>, en: list<array{string, string}>}>
     */
    public static function activityLines(): array
    {
        return [
            'call' => [
                'ar' => [['مكالمة تعريفية', 'تم التعريف بالخدمات والاتفاق على إرسال العرض.'], ['متابعة هاتفية', 'العميل يراجع الميزانية مع الإدارة المالية.']],
                'en' => [['Discovery call', 'Walked through current tools and pain points.'], ['Follow-up call', 'Budget review scheduled with finance next week.']],
            ],
            'meeting' => [
                'ar' => [['اجتماع عرض توضيحي', 'عرض توضيحي لفريق المبيعات في مقر العميل.'], ['اجتماع مراجعة العرض', 'مناقشة بنود العرض ومدة التنفيذ.']],
                'en' => [['Product demo', 'On-site demo for the sales and operations leads.'], ['Proposal review meeting', 'Went through scope, timeline and payment terms.']],
            ],
            'email' => [
                'ar' => [['إرسال عرض السعر', 'أرسلنا عرض السعر المحدث مع خيارات الدفع.'], ['تأكيد موعد الاجتماع', 'تم تأكيد الموعد يوم الأحد الساعة العاشرة صباحًا.']],
                'en' => [['Quotation sent', 'Sent the updated quotation with payment options.'], ['Meeting confirmation', 'Confirmed Sunday 10:00 at their office.']],
            ],
        ];
    }
}
