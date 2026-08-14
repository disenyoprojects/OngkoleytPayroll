<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'branch_id',
    ];

    /** The login's primary branch — the one named in the header after login. */
    public function branch() {
        return $this->belongsTo(Branch::class);
    }

    /** Every branch this login may see. One account can cover several sites. */
    public function branches() {
        return $this->belongsToMany(Branch::class);
    }

    public function isAdmin(): bool {
        return ($this->role ?? 'admin') === 'admin';
    }

    /**
     * Branch ids a non-admin login is limited to, or null for admins (who see
     * everything). Falls back to the primary branch column when no branches
     * have been attached, so a single-branch login works either way. Never
     * returns an empty array for a branch login — an unattached one is scoped
     * to nothing rather than silently to everything.
     */
    public function scopedBranchIds(): ?array {
        if ($this->isAdmin()) {
            return null;
        }

        $ids = $this->branches()->pluck('branches.id')->all();

        if (! $ids && $this->branch_id !== null) {
            $ids = [$this->branch_id];
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
