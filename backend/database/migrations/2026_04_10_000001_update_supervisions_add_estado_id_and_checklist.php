<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add estado_id FK and checklist fields
        Schema::table('supervisions', function (Blueprint $table) {
            if (! Schema::hasColumn('supervisions', 'estado_id')) {
                $table->unsignedBigInteger('estado_id')->nullable()->after('supervisor_id');
                $table->foreign('estado_id')
                      ->references('id')
                      ->on('supervision_estados')
                      ->nullOnDelete();
            }
            if (! Schema::hasColumn('supervisions', 'fachada_verificada')) {
                $table->boolean('fachada_verificada')->default(false)->after('comentario');
            }
            if (! Schema::hasColumn('supervisions', 'conexiones_verificadas')) {
                $table->boolean('conexiones_verificadas')->default(false)->after('fachada_verificada');
            }
            if (! Schema::hasColumn('supervisions', 'ubicacion_confirmada')) {
                $table->boolean('ubicacion_confirmada')->default(false)->after('conexiones_verificadas');
            }
            if (! Schema::hasColumn('supervisions', 'servicio_verificado')) {
                $table->boolean('servicio_verificado')->default(false)->after('ubicacion_confirmada');
            }
            if (! Schema::hasColumn('supervisions', 'nivel_senal')) {
                $table->string('nivel_senal')->nullable()->after('servicio_verificado');
            }
            if (! Schema::hasColumn('supervisions', 'notas_supervisor')) {
                $table->text('notas_supervisor')->nullable()->after('nivel_senal');
            }
        });

        // Migrate existing estado values → estado_id
        $estados = DB::table('supervision_estados')->pluck('id', 'nombre');

        $mapping = [
            'pendiente'  => $estados['Pendiente']  ?? null,
            'en_proceso' => $estados['En Proceso'] ?? null,
            'completado' => $estados['Finalizado'] ?? null,
        ];

        foreach ($mapping as $oldEstado => $newId) {
            if ($newId) {
                DB::table('supervisions')
                    ->where('estado', $oldEstado)
                    ->whereNull('estado_id')
                    ->update(['estado_id' => $newId]);
            }
        }

        // Rows with unknown estado — assign to Pendiente
        $pendienteId = $estados['Pendiente'] ?? null;
        if ($pendienteId) {
            DB::table('supervisions')
                ->whereNull('estado_id')
                ->update(['estado_id' => $pendienteId]);
        }
    }

    public function down(): void
    {
        Schema::table('supervisions', function (Blueprint $table) {
            foreach (['notas_supervisor','nivel_senal','servicio_verificado','ubicacion_confirmada','conexiones_verificadas','fachada_verificada'] as $col) {
                if (Schema::hasColumn('supervisions', $col)) {
                    $table->dropColumn($col);
                }
            }
            if (Schema::hasColumn('supervisions', 'estado_id')) {
                $table->dropForeign(['estado_id']);
                $table->dropColumn('estado_id');
            }
        });
    }
};
