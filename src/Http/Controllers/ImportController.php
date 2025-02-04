<?php

namespace SimonHamp\LaravelNovaCsvImport\Http\Controllers;

use Laravel\Nova\Nova;
use Laravel\Nova\Resource;
use Laravel\Nova\Rules\Relatable;
use Laravel\Nova\Actions\ActionResource;
use Laravel\Nova\Http\Requests\NovaRequest;
use SimonHamp\LaravelNovaCsvImport\Importer;
use Illuminate\Validation\ValidationException;
use Laravel\Nova\Fields\Field;

class ImportController
{
    /**
     * @var Importer
     */
    protected $importer;

    public function __construct()
    {
        $class = config('nova-csv-importer.importer');
        $this->importer = new $class;
    }

    public function preview(NovaRequest $request, $file)
    {
        $import = $this->importer
            ->toCollection($this->getFilePath($file), null)
            ->first();

        $headings = $import->first()->keys();

        $total_rows = $import->count();

        $sample = $import->take(10)->all();

        $resources = $this->getAvailableResourcesForImport($request);

        $fields = $resources->mapWithKeys(function ($resource) use ($request) {
            return $this->getAvailableFieldsForImport($resource, $request);
        });

        $resources = $resources->mapWithKeys(function ($resource) {
            return [$resource::uriKey() => $resource::label()];
        });

        return response()->json(compact('sample', 'resources', 'fields', 'total_rows', 'headings'));
    }

    public function getAvailableFieldsForImport(String $resource, $request)
    {
        $novaResource = new $resource(new $resource::$model);
        $fieldsCollection = collect($novaResource->creationFields($request));

            if (method_exists($novaResource, 'excludeAttributesFromImport')) {
                $fieldsCollection = $fieldsCollection->filter(function(Field $field) use ($novaResource, $request) {
                return !in_array($field->attribute, $novaResource::excludeAttributesFromImport($request));
            });
        }

        $fields = $fieldsCollection->map(function (Field $field) {
                    return [
                        'name' => $field->name,
                        'attribute' => $field->attribute
                    ];
                });

       return [$novaResource->uriKey() => $fields];
    }

    public function getAvailableResourcesForImport(NovaRequest $request) {

        $novaResources = collect(Nova::authorizedResources($request));

        return $novaResources->filter(function ($resource) use ($request) {
                    if ($resource === ActionResource::class) {
                        return false;
                    }

                    if (!isset($resource::$model)) {
                        return false;
                    }

                    $resourceReflection = (new \ReflectionClass((string) $resource));

                    if ($resourceReflection->hasMethod('canImportResource')) {
                        return $resource::canImportResource($request);
                    }

                    $static_vars = $resourceReflection->getStaticProperties();

                    if (!isset($static_vars['canImportResource'])) {
                        return true;
                    }

                    return isset($static_vars['canImportResource']) && $static_vars['canImportResource'];
                });
    }

    public function import(NovaRequest $request, $file)
    {
        $resource_name = $request->input('resource');
        $request->route()->setParameter('resource', $resource_name);

        $resource = Nova::resourceInstanceForKey($resource_name);
        $attribute_map = $request->input('mappings');
        $attributes = $resource->creationFields($request)->pluck('attribute');
        $rules = $this->extractValidationRules($request, $resource)->toArray();
        $model_class = get_class($resource->resource);

        $row_data = $this->importer
            ->setResource($resource)
            ->setAttributes($attributes)
            ->setAttributeMap($attribute_map)
            ->setRules($rules)
            ->setModelClass($model_class)
            ->toArray($this->getFilePath($file), null);
        foreach($row_data[0] as $row){
            $row = $this->importer->mapRowDataToAttributes($row);
            if(!$row['first_name'] ?? false) continue;
            $row['email'] = strtolower(trim($row['email']));
            if($u = \App\User::where('email',$row['email'])->first() ?? false)
            {
                $u->update([
                    "first_name" => $row['first_name'] ?? $u->first_name,
                    "last_name" => $row['last_name'] ?? $u->last_name,
                    "oe_tracker_number" => $row['oe_tracker_number'] ?? $u->oe_tracker_number,
                    "state_of_license" => $row['state_of_license'] ?? $u->state_of_license,
                    "license_number" => $row['license_number'] ?? $u->license_number,
                ]);

            }
            else
            {
               $u = \App\User::create($row);
            }

            $c = \App\Course::where('course_credit_id',$row['course_credit_id'])->orderBy('created_at','DESC')->first();
            $course_user = \App\CourseUser::firstOrNew([
                'course_id' => $c->id,
                'user_id' => $u->id,
            ]);
            $course_user->passed_at = \Carbon\Carbon::parse($row['passed_at'],'America/New_York')->utc()->toDateTimeString();


            if($course_user->sent_at ?? false)
            {
                if($row['force_send_email'] ?? false){
                    if(filter_var($row['force_send_email'], FILTER_VALIDATE_BOOLEAN)){
                        $u->notify(new \App\Notifications\LiveCourseUpload($c->id));
                        $course_user->sent_at = \Carbon\Carbon::parse($row['passed_at'],'America/New_York')->utc()->toDateTimeString();
                    }
                }

            }
            else
            {
                $u->notify(new \App\Notifications\LiveCourseUpload($c->id));
                $course_user->sent_at = \Carbon\Carbon::parse($row['passed_at'],'America/New_York')->utc()->toDateTimeString();
            }

            $course_user->save();

        }

        // if (! $this->importer->failures()->isEmpty() || ! $this->importer->errors()->isEmpty()) {
        //     return response()->json(['result' => 'failure', 'errors' => $this->importer->errors(), 'failures' => $this->importer->failures()]);
        // }

        return response()->json(['result' => 'success']);
    }

    protected function extractValidationRules($request, Resource $resource)
    {
        return collect($resource::rulesForCreation($request))->mapWithKeys(function ($rule, $key) {
            foreach ($rule as $i => $r) {
                if (! is_object($r)) {
                    continue;
                }

                // Make sure relation checks start out with a clean query
                if (is_a($r, Relatable::class)) {
                    $rule[$i] = function () use ($r) {
                        $r->query = $r->query->newQuery();
                        return $r;
                    };
                }
            }

            return [$key => $rule];
        });
    }

    protected function getFilePath($file)
    {
        return storage_path("nova/laravel-nova-import-csv/tmp/{$file}");
    }

    private function responseError($error)
    {
        throw ValidationException::withMessages([
            0 => [$error],
        ]);
    }
}
