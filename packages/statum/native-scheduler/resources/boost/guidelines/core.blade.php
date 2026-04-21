## statum/native-scheduler

A NativePHP Mobile plugin

### Installation

```bash
composer require statum/native-scheduler
```

### PHP Usage (Livewire/Blade)

Use the `NativeScheduler` facade:

@verbatim
<code-snippet name="Using NativeScheduler Facade" lang="php">
use Statum\NativeScheduler\Facades\NativeScheduler;

// Execute the plugin functionality
$result = NativeScheduler::execute(['option1' => 'value']);

// Get the current status
$status = NativeScheduler::getStatus();
</code-snippet>
@endverbatim

### Available Methods

- `NativeScheduler::execute()`: Execute the plugin functionality
- `NativeScheduler::getStatus()`: Get the current status

### JavaScript Usage (Vue/React/Inertia)

@verbatim
<code-snippet name="Using NativeScheduler in JavaScript" lang="javascript">
import { nativeScheduler } from '@statum/native-scheduler';

// Execute the plugin functionality
const result = await nativeScheduler.execute({ option1: 'value' });

// Get the current status
const status = await nativeScheduler.getStatus();
</code-snippet>
@endverbatim
