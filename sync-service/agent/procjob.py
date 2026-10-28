"""Ties child processes (the gbak pair of a live copy) to this Agent process
with a Windows Job Object (JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE): if the Agent
exits or is killed for any reason, Windows kills the children too — so a
snapshot transaction on the source database can never outlive the Agent.
No-op on non-Windows.
"""
import ctypes
import os
import sys

_JOB_OBJECT_EXTENDED_LIMIT_INFORMATION = 9
_JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE = 0x2000
_PROCESS_SET_QUOTA = 0x0100
_PROCESS_TERMINATE = 0x0001

_job = None


class _IoCounters(ctypes.Structure):
    _fields_ = [(n, ctypes.c_ulonglong) for n in (
        "ReadOperationCount", "WriteOperationCount", "OtherOperationCount",
        "ReadTransferCount", "WriteTransferCount", "OtherTransferCount",
    )]


class _BasicLimit(ctypes.Structure):
    _fields_ = [
        ("PerProcessUserTimeLimit", ctypes.c_longlong),
        ("PerJobUserTimeLimit", ctypes.c_longlong),
        ("LimitFlags", ctypes.c_uint32),
        ("MinimumWorkingSetSize", ctypes.c_size_t),
        ("MaximumWorkingSetSize", ctypes.c_size_t),
        ("ActiveProcessLimit", ctypes.c_uint32),
        ("Affinity", ctypes.c_size_t),
        ("PriorityClass", ctypes.c_uint32),
        ("SchedulingClass", ctypes.c_uint32),
    ]


class _ExtendedLimit(ctypes.Structure):
    _fields_ = [
        ("BasicLimitInformation", _BasicLimit),
        ("IoInfo", _IoCounters),
        ("ProcessMemoryLimit", ctypes.c_size_t),
        ("JobMemoryLimit", ctypes.c_size_t),
        ("PeakProcessMemoryUsed", ctypes.c_size_t),
        ("PeakJobMemoryUsed", ctypes.c_size_t),
    ]


class JobError(RuntimeError):
    pass


def _kernel32():
    k = ctypes.WinDLL("kernel32", use_last_error=True)
    k.CreateJobObjectW.restype = ctypes.c_void_p
    k.CreateJobObjectW.argtypes = [ctypes.c_void_p, ctypes.c_wchar_p]
    k.SetInformationJobObject.argtypes = [ctypes.c_void_p, ctypes.c_int, ctypes.c_void_p, ctypes.c_uint32]
    k.OpenProcess.restype = ctypes.c_void_p
    k.OpenProcess.argtypes = [ctypes.c_uint32, ctypes.c_bool, ctypes.c_uint32]
    k.AssignProcessToJobObject.argtypes = [ctypes.c_void_p, ctypes.c_void_p]
    k.CloseHandle.argtypes = [ctypes.c_void_p]
    return k


def _get_job():
    global _job
    if _job is None:
        k = _kernel32()
        handle = k.CreateJobObjectW(None, None)
        if not handle:
            raise JobError(f"CreateJobObject failed: {ctypes.get_last_error()}")
        info = _ExtendedLimit()
        info.BasicLimitInformation.LimitFlags = _JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE
        if not k.SetInformationJobObject(handle, _JOB_OBJECT_EXTENDED_LIMIT_INFORMATION, ctypes.byref(info), ctypes.sizeof(info)):
            raise JobError(f"SetInformationJobObject failed: {ctypes.get_last_error()}")
        # Deliberately never closed: the OS closes it when this process ends,
        # which is exactly what kills the children.
        _job = handle
    return _job


def bind_to_agent(pid: int) -> None:
    """Raises JobError if the child could not be bound — the caller must then
    stop the child rather than let it run unsupervised."""
    if os.name != "nt" or sys.platform != "win32":
        return
    k = _kernel32()
    job = _get_job()
    proc = k.OpenProcess(_PROCESS_SET_QUOTA | _PROCESS_TERMINATE, False, pid)
    if not proc:
        raise JobError(f"OpenProcess({pid}) failed: {ctypes.get_last_error()}")
    try:
        if not k.AssignProcessToJobObject(job, proc):
            raise JobError(f"AssignProcessToJobObject({pid}) failed: {ctypes.get_last_error()}")
    finally:
        k.CloseHandle(proc)
