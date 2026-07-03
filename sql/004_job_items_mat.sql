-- Job de SQL Server Agent que ejecuta usp_Refresh_Items_Mat cada noche (~03:00).
-- Ejecutar en la BD msdb. Ajusta @owner_login_name y la hora según tu entorno.
USE msdb;
GO
IF EXISTS (SELECT 1 FROM msdb.dbo.sysjobs WHERE name = N'Refresh_Items_Mat')
    EXEC sp_delete_job @job_name = N'Refresh_Items_Mat';
GO
EXEC sp_add_job @job_name = N'Refresh_Items_Mat';
EXEC sp_add_jobstep @job_name = N'Refresh_Items_Mat',
    @step_name = N'Ejecutar usp_Refresh_Items_Mat',
    @subsystem = N'TSQL',
    @database_name = N'INTEGRACION',
    @command = N'EXEC dbo.usp_Refresh_Items_Mat;';
EXEC sp_add_schedule @schedule_name = N'Nightly_0300',
    @freq_type = 4,            -- diario
    @freq_interval = 1,
    @active_start_time = 30000; -- 03:00:00
EXEC sp_attach_schedule @job_name = N'Refresh_Items_Mat', @schedule_name = N'Nightly_0300';
EXEC sp_add_jobserver @job_name = N'Refresh_Items_Mat';
GO
