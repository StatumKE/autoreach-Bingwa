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

## License

MIT
