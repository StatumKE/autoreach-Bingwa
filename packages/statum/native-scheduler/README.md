# NativeScheduler Plugin for NativePHP Mobile

A NativePHP Mobile plugin

## Installation

```bash
composer require statum/native-scheduler
```

## Usage

```php
use Statum\NativeScheduler\Facades\NativeScheduler;

// Execute functionality
$result = NativeScheduler::execute(['option1' => 'value']);

// Get status
$status = NativeScheduler::getStatus();
```

## Listening for Events

```php
use Livewire\Attributes\On;

#[On('native:Statum\NativeScheduler\Events\NativeSchedulerCompleted')]
public function handleNativeSchedulerCompleted($result, $id = null)
{
    // Handle the event
}
```

## License

MIT