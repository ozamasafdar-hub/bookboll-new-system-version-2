<?php

namespace App\Livewire\Admin\DeliveryTests;

use App\Livewire\Admin\Concerns\RequiresSuperAdmin;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Prove that the platform can reach people — by email and by push — before a
 * real booking depends on it.
 *
 * Two tabs, each its own component so a half-filled push form survives nothing
 * the email tab does and vice versa:
 *
 *  - Email: send a message through the configured mailer, or through SMTP
 *    settings typed in on the spot, to existing users and/or raw addresses.
 *  - Push: send a OneSignal notification to one app's devices, narrowed by
 *    platform (iOS / Android / web), to chosen users, or to raw subscription ids.
 *
 * Nothing here is queued: the point of a test is to see the outcome now.
 */
#[Layout('layouts.admin')]
#[Title('Delivery Tests')]
class Index extends Component
{
    use RequiresSuperAdmin;

    public const TABS = ['email', 'push'];

    #[Url(except: 'email')]
    public string $tab = 'email';

    public function mount(): void
    {
        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'email';
        }
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, self::TABS, true)) {
            $this->tab = $tab;
        }
    }

    public function render()
    {
        return view('livewire.admin.delivery-tests.index');
    }
}
