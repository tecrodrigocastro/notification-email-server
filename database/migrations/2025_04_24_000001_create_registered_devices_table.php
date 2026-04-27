<?php

declare(strict_types=1);

use Hyperf\Database\Schema\Schema;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('registered_devices', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('fcm_token', 512)->unique();
            $table->string('email_address');
            $table->string('display_name')->nullable();
            $table->string('imap_host');
            $table->smallInteger('imap_port')->unsigned()->default(993);
            $table->tinyInteger('imap_ssl')->default(1);
            $table->string('imap_user');
            $table->text('imap_password');
            $table->unsignedInteger('last_seen_uid')->nullable();
            $table->tinyInteger('is_active')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registered_devices');
    }
};
