<x-filament-panels::page>
    {{--
     | The two tables are footer widgets, so the only thing this template
     | adds is the band that lets somebody act on what they have just read.
     |
     | It sits above the tables on purpose, which is the opposite of the
     | attendance screen: there the band must not compete with the morning's
     | button, and here there is no button to compete with. An employee who
     | opens this page has usually come to read a decision, and the commonest
     | next act after reading "rejected" is to ask again properly.
     --}}
    @include('filament.employee.partials.quick-actions', ['tiles' => $tiles])
</x-filament-panels::page>
