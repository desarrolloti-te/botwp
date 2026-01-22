<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeadProfile extends Model
{
    use HasFactory;

    /**
     * La tabla asociada al modelo.
     * (Opcional si sigues la convención de nombres, pero bueno para ser explícito)
     */
    protected $table = 'lead_profiles';

    /**
     * Los atributos que son asignables masivamente.
     * Esto permite usar LeadProfile::create() o $profile->update($data) de forma segura.
     */
    protected $fillable = [
        'user_number',          // ID principal (Número de WhatsApp)
        'type',                 // 'unknown', 'prospect' (Nuevo), 'client' (Existente)
        
        // Datos específicos de CLIENTES
        'full_name',
        'role',
        'company',
        'current_system',
        
        // Datos específicos de PROSPECTOS (Ventas)
        'interest_service',     // Ej: "Bancos", "Nube"
        'company_size',         // Ej: "10-50 empleados"
        'has_erp_experience',   // Booleano: si han usado sistemas antes
        'pain_point',           // Su problema principal (Ej: "Auditorías", "Lentitud")
    ];

    /**
     * Conversiones de tipos nativos.
     */
    protected $casts = [
        'has_erp_experience' => 'boolean',
    ];

    /**
     * Relación opcional: Un perfil puede tener muchos chats/mensajes asociados
     * si quisieras vincularlos en el futuro.
     */
    public function chat()
    {
        return $this->belongsTo(Chat::class, 'user_number', 'user_number');
    }
}