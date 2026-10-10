<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('oms_product_id')->nullable()->index();
            $table->string('oms_main_media_id')->nullable()->index();
            $table->string('oms_main_media_checksum')->nullable();
            $table->string('oms_video_media_id')->nullable()->index();
            $table->string('oms_video_media_checksum')->nullable();
        });

        Schema::table('product_images', function (Blueprint $table) {
            $table->string('oms_media_id')->nullable()->index();
            $table->string('oms_media_checksum')->nullable();
        });

        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table) {
                $table->id();
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        if (! Schema::hasTable('job_batches')) {
            Schema::create('job_batches', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('name');
                $table->integer('total_jobs');
                $table->integer('pending_jobs');
                $table->integer('failed_jobs');
                $table->longText('failed_job_ids');
                $table->mediumText('options')->nullable();
                $table->integer('cancelled_at')->nullable();
                $table->integer('created_at');
                $table->integer('finished_at')->nullable();
            });
        }

        if (! Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('product_images')) {
            Schema::table('product_images', function (Blueprint $table) {
                $table->dropColumn(['oms_media_id', 'oms_media_checksum']);
            });
        }

        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn([
                    'oms_product_id',
                    'oms_main_media_id',
                    'oms_main_media_checksum',
                    'oms_video_media_id',
                    'oms_video_media_checksum',
                ]);
            });
        }
    }
};
