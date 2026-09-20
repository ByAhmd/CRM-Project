import { Calendar } from '@fullcalendar/core'
import dayGridPlugin from '@fullcalendar/daygrid'
import timeGridPlugin from '@fullcalendar/timegrid'
import listPlugin from '@fullcalendar/list'
import interactionPlugin from '@fullcalendar/interaction'
import arLocale from '@fullcalendar/core/locales/ar'
import enGbLocale from '@fullcalendar/core/locales/en-gb'

/*
 | The calendar page's Alpine component (decision D-12).
 |
 | Loaded as a Vite module from the page (`@vite`) and registered with
 | Alpine.data() under the name the page's x-data uses. Vite strips the
 | exports of non-library entries, so registration is a side effect rather
 | than an export: when Alpine is already on the window it is registered at
 | once (Alpine.data() before Alpine.start() only stores the factory), else
 | on `alpine:init`. Livewire starts Alpine on DOMContentLoaded, after every
 | deferred module has run, so the factory exists before x-data is read.
 | Every label, option and permission arrives in `config` from the server
 | (App\Filament\Pages\Calendar::config()); nothing here decides a rule or
 | shows a string of its own. Direction is FullCalendar's own: the page
 | passes `rtl` for Arabic.
 |
 | Tasks open the edit modal; activities (immutable) navigate to their page.
 | A drag or a resize calls moveTask() and puts the entry back when the
 | server did not move it (a refusal, a policy error, a lost request).
 |
 | Narrow viewports get a compact layout: the full toolbar (prev/next/today,
 | the title and four view buttons) needs roughly 640px of inline space in
 | either language, so below that the header keeps only prev/next and the
 | title, today and a trimmed view switcher move to a footer toolbar, and
 | the default view is the week list — a month grid at phone width is a
 | grid of ~50px cells. The choice is made at init and again whenever the
 | viewport crosses the cutoff (setOption, no re-init); every button label
 | still comes from config.buttonText, so no string lives here. The same
 | cutoff drives the toolbar CSS in the theme's calendar section.
 */
const COMPACT_MEDIA_QUERY = '(max-width: 640px)'

const COMPACT_VIEW = 'listWeek'

/* The views that are unreadable at phone width and leave it on widening. */
const WIDE_ONLY_VIEWS = ['dayGridMonth', 'timeGridWeek']

const toolbars = (compact) => {
    if (compact) {
        return {
            headerToolbar: { start: 'prev,next', center: 'title', end: '' },
            footerToolbar: { start: 'today', center: '', end: 'dayGridMonth,timeGridDay,listWeek' },
        }
    }

    return {
        headerToolbar: {
            start: 'prev,next today',
            center: 'title',
            end: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek',
        },
        footerToolbar: false,
    }
}

const crmCalendar = (config) => ({
    calendar: null,

    refresh: null,

    teardown: null,

    media: null,

    applyLayout: null,

    init() {
        const wire = this.$wire
        const methods = config.methods

        this.media = window.matchMedia(COMPACT_MEDIA_QUERY)

        const compact = this.media.matches

        // A calendar born narrow starts in the compact view by the layout's
        // hand, not the user's — remember that, so widening restores the
        // configured view exactly as a later shrink-then-grow would.
        this.forcedFrom = compact ? config.initialView : null

        this.calendar = new Calendar(this.$refs.calendar, {
            plugins: [dayGridPlugin, timeGridPlugin, listPlugin, interactionPlugin],
            locale: config.locale === 'ar' ? arLocale : enGbLocale,
            direction: config.direction,
            firstDay: config.firstDay,
            timeZone: config.timeZone,
            initialView: compact ? COMPACT_VIEW : config.initialView,
            ...toolbars(compact),
            buttonText: config.buttonText,
            allDayText: config.allDayText,
            noEventsText: config.noEventsText,
            moreLinkText: (count) => config.moreLinkText.replace(':count', String(count)),
            height: 'auto',
            nowIndicator: config.nowIndicator,
            navLinks: true,
            dayMaxEvents: true,
            slotMinTime: config.slotMinTime,
            slotMaxTime: config.slotMaxTime,
            editable: config.canMove,
            selectable: config.canCreate,
            eventDurationEditable: config.canMove,
            events: (info, success, failure) => {
                wire[methods.events](info.startStr, info.endStr).then(success).catch(failure)
            },
            eventDidMount: (info) => {
                const subject = info.event.extendedProps.subject

                if (subject) {
                    info.el.title = `${info.event.title} — ${subject}`
                }
            },
            dateClick: (info) => {
                if (! config.canCreate) {
                    return
                }

                wire.mountAction(methods.createTask, { start: info.dateStr })
            },
            eventClick: (info) => {
                info.jsEvent.preventDefault()

                if (info.event.extendedProps.type === 'task') {
                    wire.mountAction(methods.editTask, { task: Number(info.event.id.replace('task-', '')) })

                    return
                }

                if (info.event.url) {
                    window.location.assign(info.event.url)
                }
            },
            eventDrop: (info) => this.move(info),
            eventResize: (info) => this.move(info),
        })

        this.calendar.render()

        // Crossing the cutoff swaps the toolbars and, only when the current
        // view belongs to the other layout, the view itself — a view the
        // user picked that works on both sides is left alone.
        this.applyLayout = (event) => {
            if (! this.calendar) {
                return
            }

            const layout = toolbars(event.matches)

            this.calendar.setOption('headerToolbar', layout.headerToolbar)
            this.calendar.setOption('footerToolbar', layout.footerToolbar)

            if (event.matches && WIDE_ONLY_VIEWS.includes(this.calendar.view.type)) {
                // Forced off a view that cannot work at this width — remember
                // which one, so growing back restores the user's own choice.
                this.forcedFrom = this.calendar.view.type
                this.calendar.changeView(COMPACT_VIEW)
            } else if (! event.matches && this.calendar.view.type === COMPACT_VIEW && this.forcedFrom) {
                // Only undo what the shrink itself did: a user who picked the
                // compact view on a wide screen is never yanked out of it.
                this.calendar.changeView(this.forcedFrom)
                this.forcedFrom = null
            } else {
                // The user changed views while narrow: their choice stands on
                // both sides of the breakpoint from now on.
                this.forcedFrom = null
            }
        }
        this.media.addEventListener('change', this.applyLayout)

        this.refresh = () => this.calendar?.refetchEvents()
        window.addEventListener(config.refreshEvent, this.refresh)

        // A Livewire navigation replaces the page without unloading the
        // window: the calendar and its window listener would otherwise leak.
        this.teardown = () => this.destroy()
        document.addEventListener('livewire:navigating', this.teardown, { once: true })
    },

    move(info) {
        if (info.event.extendedProps.type !== 'task') {
            info.revert()

            return
        }

        const id = Number(info.event.id.replace('task-', ''))

        this.$wire[config.methods.moveTask](
            id,
            info.event.startStr,
            info.event.end ? info.event.endStr : null,
            info.event.allDay,
        )
            .then((moved) => {
                if (moved !== true) {
                    info.revert()
                }
            })
            .catch(() => info.revert())
    },

    destroy() {
        if (this.media && this.applyLayout) {
            this.media.removeEventListener('change', this.applyLayout)
            this.media = null
            this.applyLayout = null
        }

        if (this.refresh) {
            window.removeEventListener(config.refreshEvent, this.refresh)
            this.refresh = null
        }

        if (this.teardown) {
            document.removeEventListener('livewire:navigating', this.teardown)
            this.teardown = null
        }

        if (this.calendar) {
            this.calendar.destroy()
            this.calendar = null
        }
    },
})

const register = () => window.Alpine.data('crmCalendar', crmCalendar)

if (window.Alpine) {
    register()
} else {
    document.addEventListener('alpine:init', register)
}
