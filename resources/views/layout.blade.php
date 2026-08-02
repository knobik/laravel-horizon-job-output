{{--
    Overrides horizon::layout.

    Rather than shipping a copy of Horizon's layout, which would need re-syncing
    on every Horizon release, this renders Horizon's real layout from the
    horizon-original namespace and patches the output panel into it.
--}}
{!! app(\Knobik\HorizonJobOutput\LayoutDecorator::class)->decorate(
    view('horizon-original::layout', ['isDownForMaintenance' => $isDownForMaintenance])->render()
) !!}
