<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Requests\AppointmentRequest;
use App\Http\Requests\ShowServiceRequest;
use App\Category;
use App\Client;
use App\Service;
use App\Appointment;
use App\Stylist;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;


class ServicesController extends Controller
{
    /**
     * Obtener categorías y servicios.
     */
    public function index()
    {
        $categories = Category::query()
            ->orderBy('name', 'asc')
            ->with('services')
            ->get()
            ->map(function ($category) {
                $category->photo = url("storage/categories/{$category->photo}");
                return $category;
            });

            return response()->json($categories, 200);

        }

    /**
     * Agendar cita.
     */
    public function store(AppointmentRequest $request)
    {
        try {

            DB::beginTransaction();

            $client = Client::create([
                'name' => $request->input('client.name'),
                'phone' => $request->input('client.phone'),
            ]);

            $stylist = Stylist::findOrFail($request->get('stylist_id'));
            $service = Service::findOrFail($request->get('service_id'));

            $appointment = new Appointment([
                'dated_at' => Carbon::createFromFormat('Y-m-d H:i:s', $request->get('dated_at')),
                'finish_at' => Carbon::createFromFormat('Y-m-d H:i:s', $request->get('dated_at'))->addMinutes(60)->subSeconds(1),
                'duration' => 60, // Todos los servicios se agendan a una hora.
                'total' => $service->price,
            ]);
            $appointment->client()->associate($client);
            $appointment->stylist()->associate($stylist);
            $appointment->service()->associate($service);
            $appointment->save();

            $savedAppointment = Appointment::where('id', $appointment->id)
                ->with('stylist', 'client', 'service')
                ->firstOrFail();
            $savedAppointment->stylist->photo = url("storage/stylists/{$stylist->photo}");

            DB::commit();

            return response()->json($savedAppointment, 200);
        } catch (\Exception $ex) {

            Log::info($ex->getMessage());
            Log::info($ex->getTraceAsString());
            DB::rollBack();

            return response()->json(['message' => $ex->getMessage()], 500);
        }
    }

    /**
     * Obtener la información de un servicio con los
     * estilistas que prestan este servicio y las
     * fechas que ya tienen agendadas.
     */
    public function show(ShowServiceRequest $request, String $id)
    {
        $current_date = $request->get('date');
        $service = Service::query()
            ->where('id', $id)
            ->with([
                'stylists',
                'stylists.appointments' => function ($q) use ($current_date) {
                    $q->select('id', 'dated_at', 'stylist_id')
                        ->whereDate('dated_at', $current_date);
                },
            ])
            ->first();

        if ($service == null) {
            return response()->json(['message' => 'Servicio no encontrado'], 404);
        }

        $service->stylists = $service->stylists->map(function ($stylist) {
            $stylist->photo = url("storage/stylists/{$stylist->photo}");
            $stylist->locked_dates = $stylist->appointments->map(function ($appointment) {
                return $appointment->dated_at->format('Y-m-d H:i:s');
            });

            unset($stylist->pivot);
            unset($stylist->appointments);

            return $stylist;
        });

        return response()->json($service, 200);
    }
}
