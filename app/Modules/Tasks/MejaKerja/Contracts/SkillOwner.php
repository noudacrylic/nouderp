<?php

namespace App\Modules\Tasks\MejaKerja\Contracts;

/**
 * Skill karyawan virtual Asisten Owner: mesinnya sama dengan Skill Admin, tetapi task-nya
 * selalu masuk Meja Owner dan kunci karyawan virtualnya "owner.<key>".
 */
interface SkillOwner extends SkillAdmin
{
}
