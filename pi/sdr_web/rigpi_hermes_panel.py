"""Authenticated Hermes panel status and persistent settings.
Sensor conversions follow jimahlstrom/quisk hermes/quisk_widgets.py.
RF power remains raw until calibrated against an external meter.
"""
import json
import math
import os
import shutil
import tempfile
import time
from flask import jsonify, request


def validate_settings(data):
    if not isinstance(data, dict) or not data or set(data) - {"tx_enabled", "tx_drive", "rx_gain_db"}:
        raise ValueError("Choose valid Hermes settings to apply.")
    if "tx_enabled" in data and type(data["tx_enabled"]) is not bool:
        raise ValueError("TX permission must be on or off.")
    if "tx_drive" in data:
        value = data["tx_drive"]
        if type(value) is not int or value not in range(0, 241, 16):
            raise ValueError("TX drive must be one of the sixteen hardware steps.")
    if "rx_gain_db" in data:
        value = data["rx_gain_db"]
        if type(value) is not int or not -12 <= value <= 48:
            raise ValueError("Receive gain must be between -12 and +48 dB.")
    return dict(data)


def _calibrated_forward_power_profile(calibration, raw, frequency_hz, active, enforce_frequency_range=True):
    """Interpolate measured points; optionally reuse a profile outside its measured frequency window."""
    result = {"watts": None, "status": "uncalibrated"}
    try:
        if not isinstance(calibration, dict): return result
        points = sorted((float(p["raw"]), float(p["watts"])) for p in calibration["points"])
        if len(points) < 2: return result
        reference = float(calibration["frequency_hz"])
        tolerance = float(calibration["frequency_tolerance_hz"])
        minimum, maximum = float(calibration["raw_min"]), float(calibration["raw_max"])
        numbers = [v for point in points for v in point] + [reference, tolerance, minimum, maximum]
        if not all(math.isfinite(v) for v in numbers): return result
        if not (0 <= tolerance <= 100000 and 0 <= minimum <= points[0][0] < points[-1][0] <= maximum and points[0][1] > 0): return result
        if any(a[0] >= b[0] or a[1] >= b[1] for a, b in zip(points, points[1:])): return result
        result.update(reference_hz=reference, minimum_watts=points[0][1], maximum_watts=points[-1][1])
        frequency = float(frequency_hz)
        if not math.isfinite(frequency):
            result["status"] = "frequency_outside_range"; return result
        if enforce_frequency_range and abs(frequency - reference) > tolerance:
            result["status"] = "frequency_outside_range"; return result
        if not active:
            result.update(watts=0.0, status="idle"); return result
        value = float(raw)
        if not math.isfinite(value) or value < minimum:
            result["status"] = "power_outside_range"; return result
        if value > maximum:
            result["status"] = "power_above_range"; return result
        value = min(points[-1][0], max(points[0][0], value))
        for (r0, w0), (r1, w1) in zip(points, points[1:]):
            if value <= r1:
                fraction = (value - r0) / (r1 - r0)
                watts = (math.sqrt(w0) + fraction * (math.sqrt(w1) - math.sqrt(w0))) ** 2
                result.update(watts=watts, status="estimated")
                break
    except (KeyError, TypeError, ValueError, OverflowError):
        return {"watts": None, "status": "uncalibrated"}
    return result


def calibrated_forward_power(calibration, raw, frequency_hz, active):
    """Use the nearest measured profile at every frequency; retain scoped legacy profiles."""
    use_nearest_profile = isinstance(calibration, list)
    if use_nearest_profile:
        valid = []
        for profile in calibration:
            try:
                reference = float(profile["frequency_hz"])
                if math.isfinite(reference): valid.append((reference, profile))
            except (KeyError, TypeError, ValueError, OverflowError):
                continue
        if not valid: return {"watts": None, "status": "uncalibrated"}
        try:
            frequency = float(frequency_hz)
            if not math.isfinite(frequency): return {"watts": None, "status": "uncalibrated"}
        except (TypeError, ValueError, OverflowError):
            return {"watts": None, "status": "uncalibrated"}
        calibration = min(valid, key=lambda item: abs(item[0] - frequency))[1]
    return _calibrated_forward_power_profile(
        calibration, raw, frequency_hz, active,
        enforce_frequency_range=not use_nearest_profile,
    )


def install_panel(app, env):
    def panel_state():
        source = getattr(env["engine"], "hermes_source", None)
        if env["engine"].device_driver != "hermeslite":
            source = None
        native = source.stats() if source is not None else {}
        telemetry = dict(native.get("tx", {}).get("telemetry", {}))
        temperature = telemetry.get("temperature_raw")
        current = telemetry.get("pa_current_raw")
        temperature_c = (3.26 * temperature / 4096.0 - 0.5) / 0.01 if temperature is not None else None
        current_a = (3.26 * current / 4096.0) / (50.0 * 0.04) * 1.27 if current is not None else None
        tx = env["hermes_tx_controller"].status() if source is not None else {
            "active": False, "starting": False, "enabled": bool(env["HERMES_TX_ENABLED"]), "error": ""
        }
        power = calibrated_forward_power(
            env["_inst_cfg"].get("hermes_forward_power_calibrations", env["_inst_cfg"].get("hermes_forward_power_calibration")),
            telemetry.get("forward_power_raw"),
            tx.get("frequency_hz") if tx.get("active") else native.get("frequency"),
            bool(tx.get("active")),
        ) if source is not None and native.get("running") else {"watts": None, "status": "uncalibrated"}
        return {
            "connected": bool(source is not None and native.get("running")),
            "instance_port": int(env["SERVER_PORT"]),
            "device_ip": str(getattr(source, "ip", "")),
            "sample_rate": native.get("sample_rate"),
            "settings": {
                "tx_enabled": bool(env["HERMES_TX_ENABLED"]),
                "tx_drive": int(env["HERMES_TX_DRIVE"]),
                "rx_gain_db": int(getattr(source, "_lna_gain_db", env["_inst_cfg"].get("hermes_rx_gain_db", 20))),
            },
            "tx": tx,
            "telemetry": telemetry,
            "temperature_c": temperature_c,
            "pa_current_a": current_a,
            "power_calibrated": power["watts"] is not None,
            "forward_power_w": power["watts"],
            "forward_power_calibration": power,
            "ft8": env.get("_hermes_ft8_status", lambda: {"enabled": False})(),
            "packets": native.get("packets", 0),
            "packet_drops": native.get("packet_drops", 0),
        }

    env["_hermes_panel_state"] = panel_state

    def save_settings(settings):
        controller = env["hermes_tx_controller"]
        with controller.lock:
            source = getattr(env["engine"], "hermes_source", None)
            if env["engine"].device_driver != "hermeslite" or source is None:
                return "Connect this instance to Hermes before changing settings.", 409
            if controller.active or controller.starting or source.stats().get("tx", {}).get("active"):
                return "Release PTT before changing Hermes settings.", 409
            path = env["_cfg_path"]
            with open(path) as stream:
                config = json.load(stream)
            if not isinstance(config, dict) or config.get("device_driver") != "hermeslite":
                return "The saved instance is not assigned to Hermes.", 409
            keys = {"tx_enabled": "hermes_tx_enabled", "tx_drive": "hermes_tx_drive", "rx_gain_db": "hermes_rx_gain_db"}
            config.update({keys[key]: value for key, value in settings.items()})
            backup = path + ".before-panel-" + str(time.time_ns())
            shutil.copy2(path, backup)
            temporary = None
            try:
                with tempfile.NamedTemporaryFile(mode="w", dir=os.path.dirname(path), prefix=".hermes-config-", delete=False) as stream:
                    temporary = stream.name
                    json.dump(config, stream, indent=2)
                    stream.write(chr(10))
                    stream.flush()
                    os.fsync(stream.fileno())
                os.chmod(temporary, os.stat(path).st_mode & 0o777)
                os.replace(temporary, path)
                temporary = None
            finally:
                if temporary is not None:
                    os.unlink(temporary)
            if "rx_gain_db" in settings:
                source.set_lna_gain(settings["rx_gain_db"])
            if "tx_enabled" in settings:
                env["HERMES_TX_ENABLED"] = settings["tx_enabled"]
            if "tx_drive" in settings:
                env["HERMES_TX_DRIVE"] = settings["tx_drive"]
            env["_inst_cfg"].update({keys[key]: value for key, value in settings.items()})
        return None, 200

    @app.route("/api/hermes/panel", methods=["POST"])
    def api_hermes_panel():
        if not env["_hackrf_tx_bridge_authorized"]():
            return jsonify({"ok": False, "error": "unauthorized"}), 403
        data = request.get_json(silent=True)
        if not isinstance(data, dict):
            return jsonify({"ok": False, "error": "Invalid Hermes request."}), 400
        action = data.get("action", "status")
        try:
            if action == "settings":
                if request.headers.get("X-RigPi-Hermes-Admin") != "1":
                    return jsonify({"ok": False, "error": "Administrator access is required."}), 403
                settings = validate_settings(data.get("settings"))
                error, code = save_settings(settings)
                if error:
                    return jsonify({"ok": False, "error": error}), code
            elif action == "stop":
                if env["engine"].device_driver != "hermeslite":
                    return jsonify({"ok": False, "error": "Hermes is not selected."}), 409
                env["hermes_tx_controller"].stop()
            elif action != "status":
                return jsonify({"ok": False, "error": "Invalid Hermes action."}), 400
            return jsonify({"ok": True, "hermes": panel_state()})
        except ValueError as error:
            return jsonify({"ok": False, "error": str(error)}), 400
        except Exception:
            env["logger"].exception("[HERMES PANEL] request failed")
            return jsonify({"ok": False, "error": "Hermes settings could not be completed; check the service log."}), 500
