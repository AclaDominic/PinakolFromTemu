<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Services\EventService;
use App\Http\Resources\EventResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
//
class EventController extends Controller
{
    use AuthorizesRequests;

    /**
     * Get available sports dynamically from the Events table using the current year.
     */
    public function availableSports()
    {
        $currentYear = now()->year;

        // Fetch all generic sports events for the current year
        $sportsEvents = Event::where('category', 'Sports')
            ->where('date', 'LIKE', "{$currentYear}-%")
            ->get();

        $availableSports = [];

        foreach ($sportsEvents as $event) {
            // Remove common event-related suffixes to extract the core sport name
            $name = $event->name;
            $name = str_ireplace([' tryouts', ' tournament', ' league', ' match', ' championship'], '', $name);
            $availableSports[] = trim($name);
        }

        return response()->json([
            'data' => array_values(array_unique($availableSports))
        ]);
    }

    /**
     * Get available non-sports activities dynamically from the Events table using current year.
     */
    public function availableActivities()
    {
        $currentYear = now()->year;

        $activityEvents = Event::where('category', '!=', 'Sports')
            ->where('date', 'LIKE', "{$currentYear}-%")
            ->get();

        $availableActivities = [];
        foreach ($activityEvents as $event) {
            $availableActivities[] = [
                'name' => trim($event->name),
                'type' => $event->type,
                'date' => $event->date,
            ];
        }

        $uniqueActivities = collect($availableActivities)->unique('name')->values()->all();

        return response()->json([
            'data' => $uniqueActivities
        ]);
    }

    protected EventService $eventService;

    public function __construct(EventService $eventService)
    {
        $this->eventService = $eventService;
    }

    public function index()
    {
        $this->authorize('viewAny', Event::class);
        return EventResource::collection($this->eventService->getAllEvents());
    }
}
