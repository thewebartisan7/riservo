import { ChevronDownIcon, ChevronLeftIcon, ChevronRightIcon, EllipsisHorizontalIcon } from '@heroicons/react/20/solid'
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/react'

const days = [
    { date: '2021-12-27' },
    { date: '2021-12-28' },
    { date: '2021-12-29' },
    { date: '2021-12-30' },
    { date: '2021-12-31' },
    { date: '2022-01-01', isCurrentMonth: true },
    { date: '2022-01-02', isCurrentMonth: true },
    { date: '2022-01-03', isCurrentMonth: true },
    { date: '2022-01-04', isCurrentMonth: true },
    { date: '2022-01-05', isCurrentMonth: true },
    { date: '2022-01-06', isCurrentMonth: true },
    { date: '2022-01-07', isCurrentMonth: true },
    { date: '2022-01-08', isCurrentMonth: true },
    { date: '2022-01-09', isCurrentMonth: true },
    { date: '2022-01-10', isCurrentMonth: true },
    { date: '2022-01-11', isCurrentMonth: true },
    { date: '2022-01-12', isCurrentMonth: true },
    { date: '2022-01-13', isCurrentMonth: true },
    { date: '2022-01-14', isCurrentMonth: true },
    { date: '2022-01-15', isCurrentMonth: true },
    { date: '2022-01-16', isCurrentMonth: true },
    { date: '2022-01-17', isCurrentMonth: true },
    { date: '2022-01-18', isCurrentMonth: true },
    { date: '2022-01-19', isCurrentMonth: true },
    { date: '2022-01-20', isCurrentMonth: true, isToday: true },
    { date: '2022-01-21', isCurrentMonth: true },
    { date: '2022-01-22', isCurrentMonth: true, isSelected: true },
    { date: '2022-01-23', isCurrentMonth: true },
    { date: '2022-01-24', isCurrentMonth: true },
    { date: '2022-01-25', isCurrentMonth: true },
    { date: '2022-01-26', isCurrentMonth: true },
    { date: '2022-01-27', isCurrentMonth: true },
    { date: '2022-01-28', isCurrentMonth: true },
    { date: '2022-01-29', isCurrentMonth: true },
    { date: '2022-01-30', isCurrentMonth: true },
    { date: '2022-01-31', isCurrentMonth: true },
    { date: '2022-02-01' },
    { date: '2022-02-02' },
    { date: '2022-02-03' },
    { date: '2022-02-04' },
    { date: '2022-02-05' },
    { date: '2022-02-06' },
]

export default function Example() {
    return (
        <div className="flex h-full flex-col">
            <header className="flex flex-none items-center justify-between border-b border-gray-200 px-6 py-4">
                <div>
                    <h1 className="text-base font-semibold text-gray-900">
                        <time dateTime="2022-01-22" className="sm:hidden">
                            Jan 22, 2022
                        </time>
                        <time dateTime="2022-01-22" className="hidden sm:inline">
                            January 22, 2022
                        </time>
                    </h1>
                    <p className="mt-1 text-sm text-gray-500">Saturday</p>
                </div>
                <div className="flex items-center">
                    <div className="relative flex items-center rounded-md bg-white shadow-xs outline -outline-offset-1 outline-gray-300 md:items-stretch">
                        <button
                            type="button"
                            className="flex h-9 w-12 items-center justify-center rounded-l-md pr-1 text-gray-400 hover:text-gray-500 focus:relative md:w-9 md:pr-0 md:hover:bg-gray-50"
                        >
                            <span className="sr-only">Previous day</span>
                            <ChevronLeftIcon aria-hidden="true" className="size-5" />
                        </button>
                        <button
                            type="button"
                            className="hidden px-3.5 text-sm font-semibold text-gray-900 hover:bg-gray-50 focus:relative md:block"
                        >
                            Today
                        </button>
                        <span className="relative -mx-px h-5 w-px bg-gray-300 md:hidden" />
                        <button
                            type="button"
                            className="flex h-9 w-12 items-center justify-center rounded-r-md pl-1 text-gray-400 hover:text-gray-500 focus:relative md:w-9 md:pl-0 md:hover:bg-gray-50"
                        >
                            <span className="sr-only">Next day</span>
                            <ChevronRightIcon aria-hidden="true" className="size-5" />
                        </button>
                    </div>
                    <div className="hidden md:ml-4 md:flex md:items-center">
                        <Menu as="div" className="relative">
                            <MenuButton
                                type="button"
                                className="flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-xs inset-ring inset-ring-gray-300 hover:bg-gray-50"
                            >
                                Day view
                                <ChevronDownIcon aria-hidden="true" className="-mr-1 size-5 text-gray-400" />
                            </MenuButton>

                            <MenuItems
                                transition
                                className="absolute right-0 z-10 mt-3 w-36 origin-top-right overflow-hidden rounded-md bg-white shadow-lg outline-1 outline-black/5 transition data-closed:scale-95 data-closed:transform data-closed:opacity-0 data-enter:duration-100 data-enter:ease-out data-leave:duration-75 data-leave:ease-in"
                            >
                                <div className="py-1">
                                    <MenuItem>
                                        <a
                                            href="#"
                                            className="block px-4 py-2 text-sm text-gray-700 data-focus:bg-gray-100 data-focus:text-gray-900 data-focus:outline-hidden"
                                        >
                                            Day view
                                        </a>
                                    </MenuItem>
                                    <MenuItem>
                                        <a
                                            href="#"
                                            className="block px-4 py-2 text-sm text-gray-700 data-focus:bg-gray-100 data-focus:text-gray-900 data-focus:outline-hidden"
                                        >
                                            Week view
                                        </a>
                                    </MenuItem>
                                    <MenuItem>
                                        <a
                                            href="#"
                                            className="block px-4 py-2 text-sm text-gray-700 data-focus:bg-gray-100 data-focus:text-gray-900 data-focus:outline-hidden"
                                        >
                                            Month view
                                        </a>
                                    </MenuItem>
                                    <MenuItem>
                                        <a
                                            href="#"
                                            className="block px-4 py-2 text-sm text-gray-700 data-focus:bg-gray-100 data-focus:text-gray-900 data-focus:outline-hidden"
                                        >
                                            Year view
                                        </a>
                                    </MenuItem>
                                </div>
                            </MenuItems>
                        </Menu>
                        <div className="ml-6 h-6 w-px bg-gray-300" />
                        <button
                            type="button"
                            className="ml-6 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-xs hover:bg-indigo-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                        >
                            Add event
                        </button>
                    </div>
                    <div className="ml-6 md:hidden">
                        <Menu as="div" className="relative">
                            <MenuButton className="relative flex items-center rounded-full text-gray-400 outline-offset-8 hover:text-gray-500">
                                <span className="absolute -inset-2" />
                                <span className="sr-only">Open menu</span>
                                <EllipsisHorizontalIcon aria-hidden="true" className="size-5" />
                            </MenuButton>

                            <MenuItems
                                transition
                                className="absolute right-0 z-10 mt-3 w-36 origin-top-right divide-y divide-gray-100 overflow-hidden rounded-md bg-white shadow-lg outline-1 outline-black/5 transition data-closed:scale-95 data-closed:transform data-closed:opacity-0 data-enter:duration-100 data-enter:ease-out data-leave:duration-75 data-leave:ease-in"
                            >
                                <div className="py-1">
                                    <MenuItem>
                                        <a
                                            href="#"
                                            className="block px-4 py-2 text-sm text-gray-700 data-focus:bg-gray-100 data-focus:text-gray-900 data-focus:outline-hidden"
                                        >
                                            Create event
                                        </a>
                                    </MenuItem>
                                </div>
                                <div className="py-1">
                                    <MenuItem>
                                        <a
                                            href="#"
                                            className="block px-4 py-2 text-sm text-gray-700 data-focus:bg-gray-100 data-focus:text-gray-900 data-focus:outline-hidden"
                                        >
                                            Go to today
                                        </a>
                                    </MenuItem>
                                </div>
                                <div className="py-1">
                                    <MenuItem>
                                        <a
                                            href="#"
                                            className="block px-4 py-2 text-sm text-gray-700 data-focus:bg-gray-100 data-focus:text-gray-900 data-focus:outline-hidden"
                                        >
                                            Day view
                                        </a>
                                    </MenuItem>
                                    <MenuItem>
                                        <a
                                            href="#"
                                            className="block px-4 py-2 text-sm text-gray-700 data-focus:bg-gray-100 data-focus:text-gray-900 data-focus:outline-hidden"
                                        >
                                            Week view
                                        </a>
                                    </MenuItem>
                                    <MenuItem>
                                        <a
                                            href="#"
                                            className="block px-4 py-2 text-sm text-gray-700 data-focus:bg-gray-100 data-focus:text-gray-900 data-focus:outline-hidden"
                                        >
                                            Month view
                                        </a>
                                    </MenuItem>
                                    <MenuItem>
                                        <a
                                            href="#"
                                            className="block px-4 py-2 text-sm text-gray-700 data-focus:bg-gray-100 data-focus:text-gray-900 data-focus:outline-hidden"
                                        >
                                            Year view
                                        </a>
                                    </MenuItem>
                                </div>
                            </MenuItems>
                        </Menu>
                    </div>
                </div>
            </header>
            <div className="isolate flex flex-auto overflow-hidden bg-white">
                <div className="flex flex-auto flex-col overflow-auto">
                    <div className="sticky top-0 z-10 grid flex-none grid-cols-7 bg-white text-xs text-gray-500 shadow-sm ring-1 ring-black/5 md:hidden">
                        <button type="button" className="flex flex-col items-center pt-3 pb-1.5">
                            <span>W</span>
                            {/* Default: "text-gray-900", Selected: "bg-gray-900 text-white", Today (Not Selected): "text-indigo-600", Today (Selected): "bg-indigo-600 text-white" */}
                            <span className="mt-3 flex size-8 items-center justify-center rounded-full text-base font-semibold text-gray-900">
                19
              </span>
                        </button>
                        <button type="button" className="flex flex-col items-center pt-3 pb-1.5">
                            <span>T</span>
                            <span className="mt-3 flex size-8 items-center justify-center rounded-full text-base font-semibold text-indigo-600">
                20
              </span>
                        </button>
                        <button type="button" className="flex flex-col items-center pt-3 pb-1.5">
                            <span>F</span>
                            <span className="mt-3 flex size-8 items-center justify-center rounded-full text-base font-semibold text-gray-900">
                21
              </span>
                        </button>
                        <button type="button" className="flex flex-col items-center pt-3 pb-1.5">
                            <span>S</span>
                            <span className="mt-3 flex size-8 items-center justify-center rounded-full bg-gray-900 text-base font-semibold text-white">
                22
              </span>
                        </button>
                        <button type="button" className="flex flex-col items-center pt-3 pb-1.5">
                            <span>S</span>
                            <span className="mt-3 flex size-8 items-center justify-center rounded-full text-base font-semibold text-gray-900">
                23
              </span>
                        </button>
                        <button type="button" className="flex flex-col items-center pt-3 pb-1.5">
                            <span>M</span>
                            <span className="mt-3 flex size-8 items-center justify-center rounded-full text-base font-semibold text-gray-900">
                24
              </span>
                        </button>
                        <button type="button" className="flex flex-col items-center pt-3 pb-1.5">
                            <span>T</span>
                            <span className="mt-3 flex size-8 items-center justify-center rounded-full text-base font-semibold text-gray-900">
                25
              </span>
                        </button>
                    </div>
                    <div className="flex w-full flex-auto">
                        <div className="w-14 flex-none bg-white ring-1 ring-gray-100" />
                        <div className="grid flex-auto grid-cols-1 grid-rows-1">
                            {/* Horizontal lines */}
                            <div
                                style={{ gridTemplateRows: 'repeat(48, minmax(3.5rem, 1fr))' }}
                                className="col-start-1 col-end-2 row-start-1 grid divide-y divide-gray-100"
                            >
                                <div className="row-end-1 h-7" />
                                <div>
                                    <div className="-mt-2.5 -ml-14 w-14 pr-2 text-right text-xs/5 text-gray-400">12AM</div>
                                </div>
                                <div />
                                <div>
                                    <div className="-mt-2.5 -ml-14 w-14 pr-2 text-right text-xs/5 text-gray-400">1AM</div>
                                </div>
                                <div />
                                <div>
                                    <div className="-mt-2.5 -ml-14 w-14 pr-2 text-right text-xs/5 text-gray-400">2AM</div>
                                </div>
                                <div />
                                <div>
                                    <div className="-mt-2.5 -ml-14 w-14 pr-2 text-right text-xs/5 text-gray-400">3AM</div>
                                </div>
                                <div />
                                <div>
                                    <div className="-mt-2.5 -ml-14 w-14 pr-2 text-right text-xs/5 text-gray-400">4AM</div>
                                </div>
                                <div />
                                <div>
                                    <div className="-mt-2.5 -ml-14 w-14 pr-2 text-right text-xs/5 text-gray-400">5AM</div>
                                </div>
                                <div />
                                <div>
                                    <div className="-mt-2.5 -ml-14 w-14 pr-2 text-right text-xs/5 text-gray-400">6AM</div>
                                </div>
                                <div />
                                <div>
                                    <div className="-mt-2.5 -ml-14 w-14 pr-2 text-right text-xs/5 text-gray-400">7AM</div>
                                </div>
                                <div />
                                <div>
                                    <div className="-mt-2.5 -ml-14 w-14 pr-2 text-right text-xs/5 text-gray-400">8AM</div>
                                </div>
                                <div />
                                <div>
                                    <div className="-mt-2.5 -ml-14 w-14 pr-2 text-right text-xs/5 text-gray-400">9AM</div>
                                </div>
                                <div />
                                <div>
                                    <div className="-mt-2.5 -ml-14 w-14 pr-2 text-right text-xs/5 text-gray-400">10AM</div>
                                </div>
                                <div />
                                <div>
                                    <div className="-mt-2.5 -ml-14 w-14 pr-2 text-right text-xs/5 text-gray-400">11AM</div>
                                </div>
                                <div />
                                <div>
                                    <div className="-mt-2.5 -ml-14 w-14 pr-2 text-right text-xs/5 text-gray-400">12PM</div>
                                </div>
                                <div />
                                <div>
                                    <div className="-mt-2.5 -ml-14 w-14 pr-2 text-right text-xs/5 text-gray-400">1PM</div>
                                </div>
                                <div />
                                <div>
                                    <div className="-mt-2.5 -ml-14 w-14 pr-2 text-right text-xs/5 text-gray-400">2PM</div>
                                </div>
                                <div />
                                <div>
                                    <div className="-mt-2.5 -ml-14 w-14 pr-2 text-right text-xs/5 text-gray-400">3PM</div>
                                </div>
                                <div />
                                <div>
                                    <div className="-mt-2.5 -ml-14 w-14 pr-2 text-right text-xs/5 text-gray-400">4PM</div>
                                </div>
                                <div />
                                <div>
                                    <div className="-mt-2.5 -ml-14 w-14 pr-2 text-right text-xs/5 text-gray-400">5PM</div>
                                </div>
                                <div />
                                <div>
                                    <div className="-mt-2.5 -ml-14 w-14 pr-2 text-right text-xs/5 text-gray-400">6PM</div>
                                </div>
                                <div />
                                <div>
                                    <div className="-mt-2.5 -ml-14 w-14 pr-2 text-right text-xs/5 text-gray-400">7PM</div>
                                </div>
                                <div />
                                <div>
                                    <div className="-mt-2.5 -ml-14 w-14 pr-2 text-right text-xs/5 text-gray-400">8PM</div>
                                </div>
                                <div />
                                <div>
                                    <div className="-mt-2.5 -ml-14 w-14 pr-2 text-right text-xs/5 text-gray-400">9PM</div>
                                </div>
                                <div />
                                <div>
                                    <div className="-mt-2.5 -ml-14 w-14 pr-2 text-right text-xs/5 text-gray-400">10PM</div>
                                </div>
                                <div />
                                <div>
                                    <div className="-mt-2.5 -ml-14 w-14 pr-2 text-right text-xs/5 text-gray-400">11PM</div>
                                </div>
                                <div />
                            </div>

                            {/* Events */}
                            <ol
                                style={{ gridTemplateRows: '1.75rem repeat(288, minmax(0, 1fr)) auto' }}
                                className="col-start-1 col-end-2 row-start-1 grid grid-cols-1"
                            >
                                <li style={{ gridRow: '74 / span 12' }} className="relative mt-px flex">
                                    <a
                                        href="#"
                                        className="group absolute inset-1 flex flex-col overflow-y-auto rounded-lg bg-blue-50 p-2 text-xs/5 hover:bg-blue-100"
                                    >
                                        <p className="order-1 font-semibold text-blue-700">Breakfast</p>
                                        <p className="text-blue-500 group-hover:text-blue-700">
                                            <time dateTime="2022-01-22T06:00">6:00 AM</time>
                                        </p>
                                    </a>
                                </li>
                                <li style={{ gridRow: '92 / span 30' }} className="relative mt-px flex">
                                    <a
                                        href="#"
                                        className="group absolute inset-1 flex flex-col overflow-y-auto rounded-lg bg-pink-50 p-2 text-xs/5 hover:bg-pink-100"
                                    >
                                        <p className="order-1 font-semibold text-pink-700">Flight to Paris</p>
                                        <p className="order-1 text-pink-500 group-hover:text-pink-700">
                                            John F. Kennedy International Airport
                                        </p>
                                        <p className="text-pink-500 group-hover:text-pink-700">
                                            <time dateTime="2022-01-22T07:30">7:30 AM</time>
                                        </p>
                                    </a>
                                </li>
                                <li style={{ gridRow: '134 / span 18' }} className="relative mt-px flex">
                                    <a
                                        href="#"
                                        className="group absolute inset-1 flex flex-col overflow-y-auto rounded-lg bg-indigo-50 p-2 text-xs/5 hover:bg-indigo-100"
                                    >
                                        <p className="order-1 font-semibold text-indigo-700">Sightseeing</p>
                                        <p className="order-1 text-indigo-500 group-hover:text-indigo-700">Eiffel Tower</p>
                                        <p className="text-indigo-500 group-hover:text-indigo-700">
                                            <time dateTime="2022-01-22T11:00">11:00 AM</time>
                                        </p>
                                    </a>
                                </li>
                            </ol>
                        </div>
                    </div>
                </div>
                <div className="hidden w-1/2 max-w-md flex-none border-l border-gray-100 px-8 py-10 md:block">
                    <div className="flex items-center text-center text-gray-900">
                        <button
                            type="button"
                            className="-m-1.5 flex flex-none items-center justify-center p-1.5 text-gray-400 hover:text-gray-500"
                        >
                            <span className="sr-only">Previous month</span>
                            <ChevronLeftIcon aria-hidden="true" className="size-5" />
                        </button>
                        <div className="flex-auto text-sm font-semibold">January 2022</div>
                        <button
                            type="button"
                            className="-m-1.5 flex flex-none items-center justify-center p-1.5 text-gray-400 hover:text-gray-500"
                        >
                            <span className="sr-only">Next month</span>
                            <ChevronRightIcon aria-hidden="true" className="size-5" />
                        </button>
                    </div>
                    <div className="mt-6 grid grid-cols-7 text-center text-xs/6 text-gray-500">
                        <div>M</div>
                        <div>T</div>
                        <div>W</div>
                        <div>T</div>
                        <div>F</div>
                        <div>S</div>
                        <div>S</div>
                    </div>
                    <div className="isolate mt-2 grid grid-cols-7 gap-px rounded-lg bg-gray-200 text-sm shadow-sm ring-1 ring-gray-200">
                        {days.map((day) => (
                            <button
                                key={day.date}
                                type="button"
                                data-is-today={day.isToday ? '' : undefined}
                                data-is-selected={day.isSelected ? '' : undefined}
                                data-is-current-month={day.isCurrentMonth ? '' : undefined}
                                className="py-1.5 not-data-is-current-month:bg-gray-50 not-data-is-selected:not-data-is-current-month:not-data-is-today:text-gray-400 first:rounded-tl-lg last:rounded-br-lg hover:bg-gray-100 focus:z-10 data-is-current-month:bg-white not-data-is-selected:data-is-current-month:not-data-is-today:text-gray-900 data-is-current-month:hover:bg-gray-100 data-is-selected:font-semibold data-is-selected:text-white data-is-today:font-semibold data-is-today:not-data-is-selected:text-indigo-600 nth-36:rounded-bl-lg nth-7:rounded-tr-lg"
                            >
                                <time
                                    dateTime={day.date}
                                    className="mx-auto flex size-7 items-center justify-center rounded-full in-data-is-selected:not-in-data-is-today:bg-gray-900 in-data-is-selected:in-data-is-today:bg-indigo-600"
                                >
                                    {day.date.split('-').pop().replace(/^0/, '')}
                                </time>
                            </button>
                        ))}
                    </div>
                </div>
            </div>
        </div>
    )
}
