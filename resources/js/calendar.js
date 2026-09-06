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
 */
const crmCalendar = (config) => ({
    calendar: null,

    refresh: null,

    teardown: null,

    init() {
        const wire = this.$wire
        const methods = config.methods

        this.calendar = new Calendar(this.$refs.calendar, {
            plugins: [dayGridPlugin, timeGridPlugin, listPlugin, interactionPlugin],
            locale: config.locale === 'ar' ? arLocale : enGbLocale,
            direction: config.direction,
            firstDay: config.firstDay,
            timeZone: config.timeZone,
            initialView: config.initialView,
            headerToolbar: {
                start: 'prev,next today',
                center: 'title',
                end: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek',
            },
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
